<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/binloc_bulk.lib.php
 * \ingroup binloc
 * \brief   Shared bulk-assign table: one renderer, several row sources
 *
 * The reception tab, MO tab and bulk-assign page all render the same table
 * through binloc_render_bulk_table(); only their row-source providers differ.
 * Saving goes through ajax/batch_save.php via Binloc.bindBulkTable().
 */

dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

/**
 * Build bulk rows from a reception's lines
 *
 * @param  DoliDB    $db     Database handler
 * @param  Reception $object Reception with lines fetched
 * @return array             Array of stdClass bulk rows
 */
function binloc_bulk_rows_from_reception($db, $object)
{
	$rows = array();

	foreach ($object->lines as $idx => $line) {
		$fk_product  = isset($line->fk_product) ? (int) $line->fk_product : 0;
		$fk_entrepot = isset($line->fk_entrepot) ? (int) $line->fk_entrepot : 0;
		$batch       = isset($line->batch) ? trim((string) $line->batch) : '';

		if ($fk_product <= 0 || $fk_entrepot <= 0) {
			continue;
		}

		$fk_product_lot = 0;
		if (!empty($batch)) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_lot";
			$sql .= " WHERE fk_product = ".$fk_product;
			$sql .= " AND batch = '".$db->escape($batch)."'";
			$sql .= " AND entity IN (".getEntity('stock').")";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$fk_product_lot = (int) $obj->rowid;
				}
				$db->free($resql);
			}
		}

		$row = new stdClass();
		$row->key            = 'r'.$idx;
		$row->fk_product     = $fk_product;
		$row->fk_entrepot    = $fk_entrepot;
		$row->fk_product_lot = $fk_product_lot;
		$row->batch          = $batch;
		$row->product_ref    = isset($line->ref) ? $line->ref : '';
		$row->product_label  = isset($line->label) ? $line->label : (isset($line->product->label) ? $line->product->label : '');
		$row->qty            = isset($line->qty) ? (float) $line->qty : 0;
		$row->disabled_hint  = '';
		$rows[] = $row;
	}

	binloc_bulk_attach_existing($db, $rows);
	return $rows;
}

/**
 * Build bulk rows from a manufacturing order's serialized production output
 *
 * @param  DoliDB $db     Database handler
 * @param  Mo     $object Manufacturing order
 * @return array          Array of stdClass bulk rows
 */
function binloc_bulk_rows_from_mo($db, $object)
{
	global $langs;

	$rows = array();

	$sql = "SELECT mp.rowid as line_id, mp.fk_product, mp.qty, mp.batch, mp.fk_warehouse,";
	$sql .= " p.ref as product_ref, p.label as product_label,";
	$sql .= " lot.rowid as fk_product_lot";
	$sql .= " FROM ".MAIN_DB_PREFIX."mrp_production as mp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = mp.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as lot";
	$sql .= "   ON (lot.fk_product = mp.fk_product AND lot.batch = mp.batch AND lot.entity IN (".getEntity('stock')."))";
	$sql .= " WHERE mp.fk_mo = ".(int) $object->id;
	$sql .= " AND mp.role = 'produced'";
	$sql .= " AND mp.batch IS NOT NULL AND mp.batch != ''";
	$sql .= " ORDER BY mp.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $rows;
	}

	while ($obj = $db->fetch_object($resql)) {
		$row = new stdClass();
		$row->key            = 'm'.(int) $obj->line_id;
		$row->fk_product     = (int) $obj->fk_product;
		$row->fk_entrepot    = $obj->fk_warehouse ? (int) $obj->fk_warehouse : 0;
		$row->fk_product_lot = $obj->fk_product_lot ? (int) $obj->fk_product_lot : 0;
		$row->batch          = $obj->batch;
		$row->product_ref    = $obj->product_ref;
		$row->product_label  = $obj->product_label;
		$row->qty            = (float) $obj->qty;
		$row->disabled_hint  = ($row->fk_product_lot > 0) ? '' : $langs->trans('LotNotYetCreated');
		$rows[] = $row;
	}
	$db->free($resql);

	binloc_bulk_attach_existing($db, $rows);
	return $rows;
}

/**
 * Build bulk rows from all products with stock in a warehouse
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_entrepot Warehouse ID
 * @param  string $search      Search filter
 * @param  string $sortfield   Sort field
 * @param  string $sortorder   Sort order
 * @param  int    $limit       Max rows
 * @param  int    $offset      Offset
 * @return array               Array of stdClass bulk rows
 */
function binloc_bulk_rows_from_warehouse($db, $fk_entrepot, $search = '', $sortfield = 'p.ref', $sortorder = 'ASC', $limit = 0, $offset = 0)
{
	$rows = array();

	foreach (binloc_get_products_in_warehouse($db, $fk_entrepot, $search, $sortfield, $sortorder, $limit, $offset) as $obj) {
		$row = new stdClass();
		$row->key            = 'p'.(int) $obj->fk_product;
		$row->fk_product     = (int) $obj->fk_product;
		$row->fk_entrepot    = (int) $fk_entrepot;
		$row->fk_product_lot = 0;
		$row->batch          = '';
		$row->product_ref    = $obj->ref;
		$row->product_label  = $obj->label;
		$row->qty            = (float) $obj->stock;
		$row->loc_id         = (int) $obj->loc_rowid;
		$row->note           = $obj->note;
		$row->values         = $obj->values;
		$row->disabled_hint  = '';
		$rows[] = $row;
	}

	return $rows;
}

/**
 * Attach existing assignment data (loc_id, note, values) to bulk rows
 *
 * @param  DoliDB $db   Database handler
 * @param  array  $rows Bulk rows, modified in place
 * @return void
 */
function binloc_bulk_attach_existing($db, $rows)
{
	$loc = new BinlocProductLocation($db);

	$headers = array();
	foreach ($rows as $row) {
		$row->loc_id = 0;
		$row->note   = '';
		$row->values = array();

		$existing = ($row->fk_product_lot > 0)
			? $loc->findRowIdByLot($row->fk_product_lot)
			: ($row->fk_entrepot > 0 ? $loc->findRowId($row->fk_product, $row->fk_entrepot, 0) : 0);
		if ($existing > 0) {
			$row->loc_id = $existing;
			$header = new stdClass();
			$header->rowid = $existing;
			$header->target = $row;
			$headers[] = $header;
		}
	}

	if (empty($headers)) {
		return;
	}

	$ids = array();
	foreach ($headers as $h) {
		$ids[] = (int) $h->rowid;
	}
	$sql = "SELECT rowid, note FROM ".MAIN_DB_PREFIX."binloc_product_location WHERE rowid IN (".implode(',', $ids).")";
	$resql = $db->query($sql);
	if ($resql) {
		$notes = array();
		while ($obj = $db->fetch_object($resql)) {
			$notes[(int) $obj->rowid] = $obj->note;
		}
		$db->free($resql);
		foreach ($headers as $h) {
			$h->target->note = isset($notes[$h->rowid]) ? (string) $notes[$h->rowid] : '';
		}
	}

	$loc->loadValuesForRows($headers);
	foreach ($headers as $h) {
		$h->target->values = $h->values;
	}
}

/**
 * Render the shared bulk-assign table
 *
 * @param  DoliDB $db           Database handler
 * @param  array  $rows         Bulk rows from a binloc_bulk_rows_from_* provider
 * @param  array  $levels_by_wh Level configs per warehouse (fetchByWarehouses output)
 * @param  array  $opts         Options:
 *                              - table_id (string, required)
 *                              - warehouse_select (bool) allow changing the target warehouse per row
 *                              - checkboxes (bool) row checkboxes for the batch panel
 *                              - qty_label (string) header for the qty column
 * @return void  Prints HTML
 */
function binloc_render_bulk_table($db, $rows, $levels_by_wh, $opts)
{
	global $langs, $user;

	$table_id  = $opts['table_id'];
	$with_wh   = !empty($opts['warehouse_select']);
	$with_cb   = !empty($opts['checkboxes']);
	$qty_label = !empty($opts['qty_label']) ? $opts['qty_label'] : $langs->trans('Qty');

	$has_lots = false;
	foreach ($rows as $row) {
		if (!empty($row->batch)) {
			$has_lots = true;
			break;
		}
	}

	print '<table class="noborder centpercent" id="'.dol_escape_htmltag($table_id).'">';
	print '<tr class="liste_titre">';
	if ($with_cb) {
		print '<td class="center"><input type="checkbox" class="binloc-check-all"></td>';
	}
	print '<td>'.$langs->trans('Product').'</td>';
	if ($has_lots) {
		print '<td>'.$langs->trans('Batch').'/'.$langs->trans('Serial').'</td>';
	}
	print '<td class="right">'.dol_escape_htmltag($qty_label).'</td>';
	if ($with_wh) {
		print '<td>'.$langs->trans('Warehouse').'</td>';
	}
	print '<td>'.$langs->trans('BinLocation').'</td>';
	print '<td>'.$langs->trans('LocationNote').'</td>';
	print '<td class="center">'.$langs->trans('Actions').'</td>';
	print '</tr>';

	foreach ($rows as $row) {
		$disabled = ($row->disabled_hint !== '' || ($row->fk_entrepot <= 0 && !$with_wh));
		$prefix = 'row'.$row->key.'_';
		$product_url = dol_buildpath('/product/card.php?id='.$row->fk_product, 1);

		print '<tr class="oddeven binloc-bulk-row" data-key="'.dol_escape_htmltag($row->key).'"';
		print ' data-fk-product="'.$row->fk_product.'" data-fk-entrepot="'.$row->fk_entrepot.'"';
		print ' data-fk-lot="'.$row->fk_product_lot.'" data-loc-id="'.$row->loc_id.'" data-prefix="'.dol_escape_htmltag($prefix).'"';
		print ($disabled ? ' data-disabled="1"' : '').'>';

		if ($with_cb) {
			print '<td class="center"><input type="checkbox" class="binloc-row-check"'.($disabled ? ' disabled' : '').'></td>';
		}

		print '<td><a href="'.$product_url.'">'.dol_escape_htmltag($row->product_ref).'</a>';
		if ($row->product_label) {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag($row->product_label).'</span>';
		}
		print '</td>';

		if ($has_lots) {
			print '<td>';
			if (!empty($row->batch)) {
				if ($row->fk_product_lot > 0) {
					$lot_url = dol_buildpath('/product/stock/productlot_card.php?id='.$row->fk_product_lot, 1);
					print '<a href="'.$lot_url.'"><span class="badge badge-info">'.dol_escape_htmltag($row->batch).'</span></a>';
				} else {
					print '<span class="badge badge-info">'.dol_escape_htmltag($row->batch).'</span>';
				}
			} else {
				print '<span class="opacitymedium">&mdash;</span>';
			}
			print '</td>';
		}

		print '<td class="right">'.price2num($row->qty, 0).'</td>';

		if ($with_wh) {
			print '<td>';
			print binloc_render_warehouse_select($db, $prefix.'wh', $row->fk_entrepot, 'flat minwidth150 binloc-wh-select', ($disabled ? 'disabled' : ''), false);
			print '</td>';
		}

		print '<td class="binloc-levels-container" data-prefix="'.dol_escape_htmltag($prefix).'">';
		if ($disabled) {
			print '<span class="opacitymedium">'.dol_escape_htmltag($row->disabled_hint ?: $langs->trans('SelectWarehouse')).'</span>';
		} else {
			$levels = isset($levels_by_wh[$row->fk_entrepot]) ? $levels_by_wh[$row->fk_entrepot] : array();
			if (!empty($levels)) {
				print binloc_render_level_inputs($levels, $prefix, $row->values, 'flat width75 binloc-level-input');
			} else {
				print '<span class="opacitymedium">'.$langs->trans('NoLevelsConfigured').'</span>';
			}
		}
		print '</td>';

		print '<td><input type="text" class="flat width100 binloc-note-input" value="'.dol_escape_htmltag((string) $row->note).'"'.($disabled ? ' disabled' : '').'></td>';

		print '<td class="center nowraponall">';
		if (!$disabled) {
			print '<a href="#" class="binloc-filldown" title="'.dol_escape_htmltag($langs->trans('FillDownHint')).'">&darr;</a>';
			if ($row->loc_id > 0) {
				print ' <a href="#" class="binloc-row-clear" title="'.dol_escape_htmltag($langs->trans('RemoveLocation')).'">'.img_picto($langs->trans('RemoveLocation'), 'delete').'</a>';
			}
		}
		print '</td>';

		print '</tr>';
	}

	print '</table>';
}
