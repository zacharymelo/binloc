<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/binloc_csv.lib.php
 * \ingroup binloc
 * \brief   CSV import/export for warehouse layouts and bin assignments
 *
 * Two round-trip formats (export output is directly re-importable, so a
 * Google Sheets / Excel workflow is: export -> edit -> download as CSV ->
 * import):
 *
 * Layout:      warehouse;level;label;type;allowed_values
 *              one row per level, allowed_values pipe-separated ("A|B|C")
 * Assignments: product;product_label;lot;<level label per column>;note
 *              one row per assignment, per warehouse; product_label is
 *              informational and ignored on import
 *
 * Import functions run in two modes via $commit: dry-run (validate and
 * report planned actions) and apply (same logic executed in a transaction).
 */

dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binloclevaloption.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

/**
 * Read a CSV file into rows, handling UTF-8 BOM and , / ; delimiters
 *
 * @param  string $filepath Path to the uploaded file
 * @return array|false      array('header' => string[], 'rows' => string[][]) or false
 */
function binloc_csv_read($filepath)
{
	$fh = @fopen($filepath, 'r');
	if (!$fh) {
		return false;
	}

	$first = fgets($fh);
	if ($first === false) {
		fclose($fh);
		return false;
	}
	// Strip UTF-8 BOM
	if (strpos($first, "\xEF\xBB\xBF") === 0) {
		$first = substr($first, 3);
	}
	// Delimiter: whichever of ; or , splits the header into more fields
	$delim = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';

	$header = str_getcsv(rtrim($first, "\r\n"), $delim);
	$rows = array();
	while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
		if ($cells === array(null)) {
			continue; // blank line
		}
		$rows[] = array_map(function ($c) {
			return trim((string) $c);
		}, $cells);
	}
	fclose($fh);

	return array(
		'header' => array_map('trim', $header),
		'rows'   => $rows,
	);
}

/**
 * Send CSV content as a download and exit
 *
 * @param  string $filename Download filename
 * @param  array  $lines    Array of cell-arrays
 * @return void
 */
function binloc_csv_output($filename, $lines)
{
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	// BOM so Excel opens UTF-8 correctly
	print "\xEF\xBB\xBF";
	$fh = fopen('php://output', 'w');
	foreach ($lines as $line) {
		fputcsv($fh, $line, ';');
	}
	fclose($fh);
	exit;
}

/**
 * Resolve a warehouse ref to its rowid
 *
 * @param  DoliDB $db  Database handler
 * @param  string $ref Warehouse ref
 * @return int         rowid or 0
 */
function binloc_csv_resolve_warehouse($db, $ref)
{
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."entrepot";
	$sql .= " WHERE ref = '".$db->escape($ref)."' AND entity IN (".getEntity('stock').")";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;
	return $obj ? (int) $obj->rowid : 0;
}

// ---------------------------------------------------------------------------
// Layout (level configuration)
// ---------------------------------------------------------------------------

/**
 * Build the layout CSV lines for one warehouse (or all)
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_entrepot Warehouse ID, 0 for all warehouses with levels
 * @return array               Array of cell-arrays incl. header
 */
function binloc_layout_export_lines($db, $fk_entrepot = 0)
{
	$lines = array(array('warehouse', 'level', 'label', 'type', 'allowed_values'));

	$sql = "SELECT DISTINCT w.fk_entrepot, e.ref FROM ".MAIN_DB_PREFIX."binloc_warehouse_levels as w";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = w.fk_entrepot";
	$sql .= " WHERE w.entity IN (".getEntity('stock').")";
	if ($fk_entrepot > 0) {
		$sql .= " AND w.fk_entrepot = ".(int) $fk_entrepot;
	}
	$sql .= " ORDER BY e.ref ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $lines;
	}

	$levelObj = new BinlocWarehouseLevel($db);
	while ($obj = $db->fetch_object($resql)) {
		$num = 0;
		foreach ($levelObj->fetchByWarehouse($obj->fk_entrepot) as $cfg) {
			$num++;
			$values = array();
			foreach ($cfg->options as $opt) {
				if ($opt->active) {
					$values[] = $opt->value;
				}
			}
			$lines[] = array($obj->ref, $num, $cfg->label, $cfg->datatype, implode('|', $values));
		}
	}
	$db->free($resql);

	return $lines;
}

/**
 * Import a layout CSV: create/update levels (matched by label, case-insensitive)
 * and add missing allowed values. Additive only — levels or values absent from
 * the file are reported but never removed.
 *
 * @param  DoliDB $db     Database handler
 * @param  array  $parsed Output of binloc_csv_read()
 * @param  User   $user   User performing action
 * @param  bool   $commit false = dry-run report only, true = apply in a transaction
 * @return array          Report: rows[] {line, warehouse, label, action, detail},
 *                        errors[] strings, counts {create_level, update_level, add_value, unchanged}
 */
function binloc_layout_import_run($db, $parsed, $user, $commit = false)
{
	global $langs;

	$report = array('rows' => array(), 'errors' => array(), 'counts' => array('create_level' => 0, 'update_level' => 0, 'add_value' => 0, 'unchanged' => 0));

	$expected = array('warehouse', 'level', 'label', 'type', 'allowed_values');
	$header = array_map('strtolower', $parsed['header']);
	if (array_slice($header, 0, 5) !== $expected) {
		$report['errors'][] = $langs->trans('CsvBadHeader', implode(';', $expected), implode(';', $parsed['header']));
		return $report;
	}

	// Group rows per warehouse, resolving refs and validating first
	$per_wh = array();
	foreach ($parsed['rows'] as $i => $cells) {
		$line = $i + 2;
		$wh_ref   = isset($cells[0]) ? $cells[0] : '';
		$position = isset($cells[1]) ? (int) $cells[1] : 0;
		$label    = isset($cells[2]) ? $cells[2] : '';
		$type     = isset($cells[3]) ? strtolower($cells[3]) : 'text';
		$values   = (isset($cells[4]) && $cells[4] !== '') ? array_map('trim', explode('|', $cells[4])) : array();

		if ($wh_ref === '' && $label === '') {
			continue;
		}
		$fk_entrepot = binloc_csv_resolve_warehouse($db, $wh_ref);
		if ($fk_entrepot <= 0) {
			$report['errors'][] = $langs->trans('CsvUnknownWarehouse', $line, $wh_ref);
			continue;
		}
		if ($label === '' || dol_strlen($label) > 64) {
			$report['errors'][] = $langs->trans('CsvBadLabel', $line);
			continue;
		}
		if (!in_array($type, array('text', 'number', 'list'), true)) {
			$report['errors'][] = $langs->trans('CsvBadType', $line, $cells[3]);
			continue;
		}
		$per_wh[$fk_entrepot]['ref'] = $wh_ref;
		$per_wh[$fk_entrepot]['levels'][] = array('line' => $line, 'position' => $position, 'label' => $label, 'type' => $type, 'values' => $values);
	}

	if (!empty($report['errors'])) {
		return $report; // never apply a partially valid layout file
	}

	if ($commit) {
		$db->begin();
	}

	$levelObj  = new BinlocWarehouseLevel($db);
	$optionObj = new BinlocLevelOption($db);

	foreach ($per_wh as $fk_entrepot => $data) {
		$existing = $levelObj->fetchByWarehouse($fk_entrepot, true);

		// Index existing levels by lowercase label
		$by_label = array();
		foreach ($existing as $id => $cfg) {
			$by_label[strtolower($cfg->label)] = $cfg;
		}

		// Sort file levels by their stated position, falling back to file order
		usort($data['levels'], function ($a, $b) {
			return ($a['position'] ?: PHP_INT_MAX) <=> ($b['position'] ?: PHP_INT_MAX);
		});

		$apply_rows = array();
		$position = 0;
		$seen_ids = array();
		foreach ($data['levels'] as $lvl) {
			$position++;
			$match = isset($by_label[strtolower($lvl['label'])]) ? $by_label[strtolower($lvl['label'])] : null;
			$action = $match ? (($match->datatype !== $lvl['type'] || $match->position != $position || !$match->active) ? 'update_level' : 'unchanged') : 'create_level';
			$report['counts'][$action]++;
			$report['rows'][] = array('line' => $lvl['line'], 'warehouse' => $data['ref'], 'label' => $lvl['label'], 'action' => $action, 'detail' => $lvl['type'].' @'.$position);
			$apply_rows[] = array('id' => ($match ? $match->id : 0), 'label' => $lvl['label'], 'datatype' => $lvl['type'], 'position' => $position);
			if ($match) {
				$seen_ids[$match->id] = true;
			}
		}
		// Levels in DB but not in the file stay untouched (additive import)
		foreach ($existing as $id => $cfg) {
			if (!isset($seen_ids[$id])) {
				$position++;
				$apply_rows[] = array('id' => $id, 'label' => $cfg->label, 'datatype' => $cfg->datatype, 'position' => $position);
				$report['rows'][] = array('line' => 0, 'warehouse' => $data['ref'], 'label' => $cfg->label, 'action' => 'kept', 'detail' => $langs->trans('CsvLevelNotInFile'));
			}
		}

		if ($commit) {
			if ($levelObj->applyWarehouseLevels($fk_entrepot, $apply_rows, $user) < 0) {
				$report['errors'][] = $levelObj->error;
				$db->rollback();
				return $report;
			}
			// Reload to map labels -> level ids (new levels included)
			$existing = $levelObj->fetchByWarehouse($fk_entrepot, true);
			$by_label = array();
			foreach ($existing as $id => $cfg) {
				$by_label[strtolower($cfg->label)] = $cfg;
			}
		}

		// Allowed values: add missing (matched case-insensitively), never remove
		foreach ($data['levels'] as $lvl) {
			if ($lvl['type'] !== 'list' || empty($lvl['values'])) {
				continue;
			}
			$match = isset($by_label[strtolower($lvl['label'])]) ? $by_label[strtolower($lvl['label'])] : null;
			$have = array();
			if ($match) {
				foreach ($match->options as $opt) {
					$have[strtolower($opt->value)] = true;
				}
			}
			$pos = $match ? count($match->options) : 0;
			foreach ($lvl['values'] as $value) {
				if ($value === '' || isset($have[strtolower($value)])) {
					continue;
				}
				$have[strtolower($value)] = true;
				$pos++;
				$report['counts']['add_value']++;
				$report['rows'][] = array('line' => $lvl['line'], 'warehouse' => $data['ref'], 'label' => $lvl['label'], 'action' => 'add_value', 'detail' => $value);
				if ($commit) {
					$result = $optionObj->create($match->id, $value, $pos, $user);
					if ($result < 0 && $result != -2) {
						$report['errors'][] = $optionObj->error;
						$db->rollback();
						return $report;
					}
				}
			}
		}
	}

	if ($commit) {
		$db->commit();
	}

	return $report;
}

// ---------------------------------------------------------------------------
// Assignments (product -> bin)
// ---------------------------------------------------------------------------

/**
 * Build the assignments CSV lines for one warehouse
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_entrepot Warehouse ID
 * @return array               Array of cell-arrays incl. header
 */
function binloc_assign_export_lines($db, $fk_entrepot)
{
	$levelObj = new BinlocWarehouseLevel($db);
	$levels = $levelObj->fetchByWarehouse($fk_entrepot);

	$header = array('product', 'product_label', 'lot');
	foreach ($levels as $cfg) {
		$header[] = $cfg->label;
	}
	$header[] = 'note';
	$lines = array($header);

	$locObj = new BinlocProductLocation($db);
	foreach ($locObj->fetchAllByWarehouse($fk_entrepot, '', 'p.ref', 'ASC', 0, 0) as $row) {
		$cells = array($row->product_ref, $row->product_label, (string) $row->lot_batch);
		foreach ($levels as $level_id => $cfg) {
			$cells[] = isset($row->values[$level_id]) ? (string) $row->values[$level_id]->display : '';
		}
		$cells[] = (string) $row->note;
		$lines[] = $cells;
	}

	return $lines;
}

/**
 * Import an assignments CSV for one warehouse.
 *
 * Level columns are matched to the warehouse's levels by header label
 * (case-insensitive); only columns present in the file are authoritative —
 * for those, an empty cell clears the level. A row whose present level cells
 * are all empty clears (deletes) an existing assignment. Rows are upserts
 * keyed on (product ref, lot batch).
 *
 * @param  DoliDB $db             Database handler
 * @param  int    $fk_entrepot    Warehouse ID
 * @param  array  $parsed         Output of binloc_csv_read()
 * @param  bool   $create_missing Create unknown dropdown values instead of rejecting the row
 * @param  User   $user           User performing action
 * @param  bool   $commit         false = dry-run report only, true = apply in a transaction
 * @return array                  Report: rows[] {line, product, lot, action, detail},
 *                                errors[] strings, counts {create, update, clear, skip, value_add}
 */
function binloc_assign_import_run($db, $fk_entrepot, $parsed, $create_missing, $user, $commit = false)
{
	global $langs;

	$report = array('rows' => array(), 'errors' => array(), 'counts' => array('create' => 0, 'update' => 0, 'clear' => 0, 'skip' => 0, 'value_add' => 0));

	$levelObj  = new BinlocWarehouseLevel($db);
	$optionObj = new BinlocLevelOption($db);
	$levels = $levelObj->fetchByWarehouse($fk_entrepot);
	if (empty($levels)) {
		$report['errors'][] = $langs->trans('NoLevelsConfigured');
		return $report;
	}

	// Map file columns: fixed columns by name, level columns by label (CI)
	$col_product = null;
	$col_lot = null;
	$col_note = null;
	$col_levels = array(); // column index => level id
	$levels_by_label = array();
	foreach ($levels as $id => $cfg) {
		$levels_by_label[strtolower($cfg->label)] = $id;
	}
	foreach ($parsed['header'] as $idx => $name) {
		$key = strtolower(trim($name));
		if ($key === 'product') {
			$col_product = $idx;
		} elseif ($key === 'lot') {
			$col_lot = $idx;
		} elseif ($key === 'note') {
			$col_note = $idx;
		} elseif ($key === 'product_label' || $key === '') {
			continue; // informational
		} elseif (isset($levels_by_label[$key])) {
			$col_levels[$idx] = $levels_by_label[$key];
		} else {
			$report['errors'][] = $langs->trans('CsvUnknownColumn', $name);
		}
	}
	if ($col_product === null) {
		$report['errors'][] = $langs->trans('CsvMissingProductColumn');
	}
	if (!empty($report['errors'])) {
		return $report;
	}

	if ($commit) {
		$db->begin();
	}

	$locObj = new BinlocProductLocation($db);
	$row_errors = 0;

	foreach ($parsed['rows'] as $i => $cells) {
		$line = $i + 2;
		$product_ref = isset($cells[$col_product]) ? $cells[$col_product] : '';
		$lot_batch   = ($col_lot !== null && isset($cells[$col_lot])) ? $cells[$col_lot] : '';
		if ($product_ref === '') {
			continue;
		}

		// Resolve product
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product";
		$sql .= " WHERE ref = '".$db->escape($product_ref)."' AND entity IN (".getEntity('product').")";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			$report['errors'][] = $langs->trans('CsvUnknownProduct', $line, $product_ref);
			$row_errors++;
			continue;
		}
		$fk_product = (int) $obj->rowid;

		// Resolve lot
		$fk_product_lot = 0;
		if ($lot_batch !== '') {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_lot";
			$sql .= " WHERE fk_product = ".$fk_product." AND batch = '".$db->escape($lot_batch)."'";
			$sql .= " AND entity IN (".getEntity('stock').")";
			$resql = $db->query($sql);
			$obj = $resql ? $db->fetch_object($resql) : null;
			if (!$obj) {
				$report['errors'][] = $langs->trans('CsvUnknownLot', $line, $lot_batch, $product_ref);
				$row_errors++;
				continue;
			}
			$fk_product_lot = (int) $obj->rowid;
		}

		// Load existing assignment (levels absent from the file stay untouched)
		$loc = new BinlocProductLocation($db);
		$existing_id = ($fk_product_lot > 0) ? $loc->findRowIdByLot($fk_product_lot) : $loc->findRowId($fk_product, $fk_entrepot, 0);
		if ($existing_id > 0) {
			$loc->fetch($existing_id);
		}
		$loc->fk_product     = $fk_product;
		$loc->fk_entrepot    = $fk_entrepot;
		$loc->fk_product_lot = $fk_product_lot;
		if ($col_note !== null && isset($cells[$col_note])) {
			$loc->note = dol_string_nohtmltag($cells[$col_note]);
		}

		// Apply level cells; present columns are authoritative (empty = clear)
		$bad_row = false;
		foreach ($col_levels as $idx => $level_id) {
			$value = isset($cells[$idx]) ? $cells[$idx] : '';
			$cfg = $levels[$level_id];

			if ($value === '') {
				unset($loc->values[$level_id]);
				continue;
			}

			if ($cfg->datatype === 'list') {
				$opt_id = 0;
				foreach ($cfg->options as $opt) {
					if (strtolower($opt->value) === strtolower($value)) {
						$opt_id = $opt->id;
						break;
					}
				}
				if (!$opt_id) {
					if (!$create_missing) {
						$report['errors'][] = $langs->trans('CsvUnknownValue', $line, $value, $cfg->label);
						$row_errors++;
						$bad_row = true;
						break;
					}
					$report['counts']['value_add']++;
					$report['rows'][] = array('line' => $line, 'product' => $product_ref, 'lot' => $lot_batch, 'action' => 'value_add', 'detail' => $cfg->label.': '.$value);
					if ($commit) {
						$opt_id = $optionObj->create($level_id, $value, count($cfg->options) + 1, $user);
						if ($opt_id <= 0) {
							$report['errors'][] = $optionObj->error;
							$db->rollback();
							return $report;
						}
					} else {
						$opt_id = -1; // placeholder for dry-run
					}
					// Keep the in-memory config in sync for later rows
					$new_opt = new stdClass();
					$new_opt->id = $opt_id;
					$new_opt->value = $value;
					$new_opt->position = count($cfg->options) + 1;
					$new_opt->active = 1;
					$cfg->options[] = $new_opt;
				}
				$entry = new stdClass();
				$entry->fk_option = $opt_id;
				$entry->value = null;
				$entry->display = $value;
				$loc->values[$level_id] = $entry;
			} else {
				if ($loc->setRawValue($cfg, $value) < 0) {
					$report['errors'][] = $langs->trans('CsvBadValue', $line, $value, $cfg->label, $langs->trans($loc->error));
					$row_errors++;
					$bad_row = true;
					break;
				}
			}
		}
		if ($bad_row) {
			continue;
		}

		// Decide the action
		if (!$loc->hasValues()) {
			if ($existing_id > 0) {
				$report['counts']['clear']++;
				$report['rows'][] = array('line' => $line, 'product' => $product_ref, 'lot' => $lot_batch, 'action' => 'clear', 'detail' => '');
				if ($commit) {
					$loc->id = $existing_id;
					if ($loc->delete($user) < 0) {
						$report['errors'][] = $loc->error;
						$db->rollback();
						return $report;
					}
				}
			} else {
				$report['counts']['skip']++;
			}
			continue;
		}

		$action = ($existing_id > 0) ? 'update' : 'create';
		$report['counts'][$action]++;
		$parts = array();
		foreach ($loc->values as $lid => $entry) {
			$parts[] = (isset($levels[$lid]) ? $levels[$lid]->label : $lid).': '.$entry->display;
		}
		$report['rows'][] = array('line' => $line, 'product' => $product_ref, 'lot' => $lot_batch, 'action' => $action, 'detail' => implode(' / ', $parts));

		if ($commit) {
			if ($loc->createOrUpdate($user) < 0) {
				$report['errors'][] = $langs->trans('CsvRowFailed', $line, $langs->trans($loc->error));
				$db->rollback();
				return $report;
			}
		}
	}

	if ($commit) {
		if ($row_errors > 0) {
			// Apply is all-or-nothing: a file with invalid rows is never partially imported
			$db->rollback();
			return $report;
		}
		$db->commit();
	}

	return $report;
}
