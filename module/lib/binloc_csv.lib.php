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
 * Stock+bins:  product;product_label;lot;qty_in_stock;bin_status;<levels>;note
 *              export only: the assignments format plus two informational
 *              columns, one row per stocked or assigned product (and lot)
 *
 * Import functions run in two modes via $commit: dry-run (validate and
 * report planned actions) and apply (same logic executed in a transaction).
 */

dol_include_once('/binloc/lib/binloc.lib.php');
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
			// Letter levels that are a clean, gapless A..end sequence export as
			// just "end" (the shorthand binloc_layout_import_run() re-expands) —
			// far more readable/editable than spelling out 26+ codes in a cell
			$cell = implode('|', $values);
			if ($cfg->datatype === 'letter' && !empty($values)) {
				$end = end($values);
				if (binloc_letter_sequence($end) === $values) {
					$cell = $end;
				}
			}
			$lines[] = array($obj->ref, $num, $cfg->label, $cfg->datatype, $cell);
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
		// Letter shorthand: a single token (no "|") that looks like a letter code
		// (e.g. "Z" or "AC") means "generate the whole A..that range", exactly
		// like the Generate button — the common case, since typing all 26+
		// codes by hand in a spreadsheet cell defeats the point of the type.
		// A pipe-separated list is always taken literally, for both types.
		if ($type === 'letter' && count($values) === 1) {
			$sequence = binloc_letter_sequence($values[0]);
			if (!empty($sequence)) {
				$values = $sequence;
			}
		}

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
		if (!in_array($type, array('text', 'number', 'list', 'letter'), true)) {
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
			$changes = array();
			if ($match) {
				if ($match->datatype !== $lvl['type']) {
					$changes[] = array($langs->trans('Type'), $match->datatype, $lvl['type']);
				}
				if ($match->position != $position) {
					$changes[] = array($langs->trans('CsvLevelPosition'), (string) $match->position, (string) $position);
				}
				if (!$match->active) {
					$changes[] = array($langs->trans('Status'), $langs->trans('Disabled'), $langs->trans('Enabled'));
				}
				$action = $changes ? 'update_level' : 'unchanged';
			} else {
				$action = 'create_level';
				$changes[] = array($langs->trans('Type'), '', $lvl['type']);
				$changes[] = array($langs->trans('CsvLevelPosition'), '', (string) $position);
			}
			$report['counts'][$action]++;
			$report['rows'][] = array('line' => $lvl['line'], 'ref' => $data['ref'], 'sub' => $lvl['label'], 'action' => $action, 'changes' => $action === 'unchanged' ? array() : $changes, 'detail' => $action === 'unchanged' ? $lvl['type'].', '.$langs->trans('CsvLevelPosition').' '.$position : '');
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
				$report['rows'][] = array('line' => 0, 'ref' => $data['ref'], 'sub' => $cfg->label, 'action' => 'kept', 'changes' => array(), 'detail' => $langs->trans('CsvLevelNotInFile'));
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
			if (!in_array($lvl['type'], array('list', 'letter'), true) || empty($lvl['values'])) {
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
			$added = array();
			foreach ($lvl['values'] as $value) {
				if ($value === '' || isset($have[strtolower($value)])) {
					continue;
				}
				$have[strtolower($value)] = true;
				$pos++;
				$report['counts']['add_value']++;
				$added[] = $value;
				if ($commit) {
					$result = $optionObj->create($match->id, $value, $pos, $user);
					if ($result < 0 && $result != -2) {
						$report['errors'][] = $optionObj->error;
						$db->rollback();
						return $report;
					}
				}
			}
			// One row per level, not per value: a letter range adds dozens at once
			if ($added) {
				$report['rows'][] = array('line' => $lvl['line'], 'ref' => $data['ref'], 'sub' => $lvl['label'], 'action' => 'add_value', 'changes' => array(array($langs->trans('CsvAllowedValues'), '', implode(', ', $added))), 'detail' => '');
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
 * Informational columns of the stock export, ignored on import
 *
 * @return string[] Lowercase column names
 */
function binloc_stock_export_info_columns()
{
	return array('qty_in_stock', 'bin_status');
}

/**
 * Build the "stock and bins" CSV lines for one warehouse: every product with
 * stock there, plus every product or lot already assigned there, with its
 * quantity and whether it still needs a bin.
 *
 * The file is an assignments file with two extra informational columns, so
 * filling the empty level cells and importing it assigns the missing bins.
 * Product rows use the same set as the Bulk assign page
 * (binloc_get_products_in_warehouse); lot-level assignments follow their
 * product's row.
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_entrepot Warehouse ID
 * @return array               Array of cell-arrays incl. header
 */
function binloc_stock_export_lines($db, $fk_entrepot)
{
	global $langs;

	$levelObj = new BinlocWarehouseLevel($db);
	$levels = $levelObj->fetchByWarehouse($fk_entrepot);

	$header = array('product', 'product_label', 'lot', 'qty_in_stock', 'bin_status');
	foreach ($levels as $cfg) {
		$header[] = $cfg->label;
	}
	$header[] = 'note';
	$lines = array($header);

	// Lot quantities in this warehouse, keyed "fk_product|batch"
	$lot_qty = array();
	$sql = "SELECT ps.fk_product, pb.batch, pb.qty";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " WHERE ps.fk_entrepot = ".(int) $fk_entrepot;
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$lot_qty[$obj->fk_product.'|'.$obj->batch] = (float) $obj->qty;
		}
		$db->free($resql);
	}

	// Lot-level assignments, grouped per product
	$lot_rows = array();
	$locObj = new BinlocProductLocation($db);
	foreach ($locObj->fetchAllByWarehouse($fk_entrepot, '', 'p.ref', 'ASC', 0, 0) as $row) {
		if ($row->fk_product_lot > 0) {
			$lot_rows[$row->fk_product][] = $row;
		}
	}

	$status_assigned = $langs->transnoentitiesnoconv('CsvBinStatusAssigned');
	$status_needed   = $langs->transnoentitiesnoconv('CsvBinStatusNeeded');
	$status_per_lot  = $langs->transnoentitiesnoconv('CsvBinStatusPerLot');

	$line_for = function ($ref, $label, $lot, $qty, $status, $values, $note) use ($levels) {
		$cells = array($ref, $label, (string) $lot, (string) (0 + $qty), $status);
		foreach ($levels as $level_id => $cfg) {
			$cells[] = isset($values[$level_id]) ? (string) $values[$level_id]->display : '';
		}
		$cells[] = (string) $note;
		return $cells;
	};
	$add_lots = function ($fk_product) use (&$lot_rows, &$lines, $lot_qty, $line_for, $status_assigned) {
		if (empty($lot_rows[$fk_product])) {
			return;
		}
		foreach ($lot_rows[$fk_product] as $row) {
			$key = $fk_product.'|'.$row->lot_batch;
			$qty = isset($lot_qty[$key]) ? $lot_qty[$key] : 0;
			$lines[] = $line_for($row->product_ref, $row->product_label, $row->lot_batch, $qty, $status_assigned, $row->values, $row->note);
		}
		unset($lot_rows[$fk_product]);
	};

	foreach (binloc_get_products_in_warehouse($db, $fk_entrepot, '', 'p.ref', 'ASC') as $obj) {
		$fk_product = (int) $obj->fk_product;
		if ($obj->loc_rowid > 0) {
			$status = $status_assigned;
		} elseif (!empty($lot_rows[$fk_product])) {
			$status = $status_per_lot;
		} else {
			$status = $status_needed;
		}
		$lines[] = $line_for($obj->ref, $obj->label, '', $obj->stock, $status, $obj->values, $obj->note);
		$add_lots($fk_product);
	}
	// Lots assigned here whose product has neither stock nor a product-level bin
	foreach (array_keys($lot_rows) as $fk_product) {
		$add_lots($fk_product);
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
 * @return array                  Report: rows[] {line, ref, sub, action, changes[], detail},
 *                                errors[] strings, counts {create, update, clear, unchanged, skip, value_add}
 */
function binloc_assign_import_run($db, $fk_entrepot, $parsed, $create_missing, $user, $commit = false)
{
	global $langs;

	$report = array('rows' => array(), 'errors' => array(), 'counts' => array('create' => 0, 'update' => 0, 'clear' => 0, 'unchanged' => 0, 'skip' => 0, 'value_add' => 0));

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
		} elseif (in_array($key, binloc_stock_export_info_columns(), true)) {
			continue; // informational (stock export)
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
		// What is saved now, to report field-by-field changes and skip no-op rows
		$before = binloc_assign_snapshot($loc);
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

			if (binloc_datatype_has_options($cfg->datatype)) {
				$opt_id = 0;
				foreach ($cfg->options as $opt) {
					if (strtolower($opt->value) === strtolower($value)) {
						$opt_id = $opt->id;
						$value = $opt->value; // the stored spelling, not the file's case
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
					$report['rows'][] = array('line' => $line, 'ref' => $product_ref, 'sub' => $lot_batch, 'action' => 'value_add', 'changes' => array(array($cfg->label, '', $value)), 'detail' => '');
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

		// Decide the action from what actually differs
		$after = binloc_assign_snapshot($loc);
		$changes = binloc_assign_diff($levels, $before, $after);

		if (!$loc->hasValues()) {
			if ($existing_id > 0) {
				$report['counts']['clear']++;
				$report['rows'][] = array('line' => $line, 'ref' => $product_ref, 'sub' => $lot_batch, 'action' => 'clear', 'changes' => binloc_assign_diff($levels, $before, array('values' => array(), 'note' => '')), 'detail' => '');
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
				$report['rows'][] = array('line' => $line, 'ref' => $product_ref, 'sub' => $lot_batch, 'action' => 'skip', 'changes' => array(), 'detail' => $langs->trans('CsvSkipNoBin'));
			}
			continue;
		}

		if ($existing_id > 0 && empty($changes)) {
			$report['counts']['unchanged']++;
			$report['rows'][] = array('line' => $line, 'ref' => $product_ref, 'sub' => $lot_batch, 'action' => 'unchanged', 'changes' => array(), 'detail' => binloc_assign_summary($levels, $after));
			continue;
		}

		$action = ($existing_id > 0) ? 'update' : 'create';
		$report['counts'][$action]++;
		$report['rows'][] = array('line' => $line, 'ref' => $product_ref, 'sub' => $lot_batch, 'action' => $action, 'changes' => $changes, 'detail' => '');

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

/**
 * Comparable picture of an assignment: level id => {key, display}, and note
 *
 * The key is the option rowid for dropdown levels and the raw value
 * otherwise, so a file that spells "b" for option "B" is not a change.
 *
 * @param  BinlocProductLocation $loc Assignment (fetched, or being built)
 * @return array{values:array<int,array{key:string,display:string}>,note:string}
 */
function binloc_assign_snapshot($loc)
{
	$values = array();
	foreach ((array) $loc->values as $lid => $entry) {
		$display = (string) $entry->display;
		if ($display === '' && empty($entry->fk_option)) {
			continue;
		}
		$key = !empty($entry->fk_option) && $entry->fk_option > 0 ? 'o'.$entry->fk_option : 'v'.$display;
		$values[(int) $lid] = array('key' => $key, 'display' => $display);
	}
	return array('values' => $values, 'note' => trim((string) $loc->note));
}

/**
 * Field-by-field differences between two assignment snapshots
 *
 * @param  array $levels Level configs of the warehouse (id => cfg), in position order
 * @param  array $before From binloc_assign_snapshot()
 * @param  array $after  From binloc_assign_snapshot()
 * @return array<int,array{0:string,1:string,2:string}> [field, from, to]; '' = empty
 */
function binloc_assign_diff($levels, $before, $after)
{
	global $langs;

	$changes = array();
	foreach ($levels as $lid => $cfg) {
		$b = isset($before['values'][$lid]) ? $before['values'][$lid] : null;
		$a = isset($after['values'][$lid]) ? $after['values'][$lid] : null;
		if (($b ? $b['key'] : '') !== ($a ? $a['key'] : '')) {
			$changes[] = array($cfg->label, $b ? $b['display'] : '', $a ? $a['display'] : '');
		}
	}
	if ($before['note'] !== $after['note']) {
		$changes[] = array($langs->trans('Note'), $before['note'], $after['note']);
	}
	return $changes;
}

/**
 * One-line "Level: value / Level: value" of a snapshot (for unchanged rows)
 *
 * @param  array $levels   Level configs of the warehouse (id => cfg)
 * @param  array $snapshot From binloc_assign_snapshot()
 * @return string
 */
function binloc_assign_summary($levels, $snapshot)
{
	$parts = array();
	foreach ($levels as $lid => $cfg) {
		if (isset($snapshot['values'][$lid])) {
			$parts[] = $cfg->label.': '.$snapshot['values'][$lid]['display'];
		}
	}
	return implode(' / ', $parts);
}
