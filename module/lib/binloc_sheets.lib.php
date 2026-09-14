<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/binloc_sheets.lib.php
 * \ingroup binloc
 * \brief   Pick / place sheet rows from sales orders, shipments and receptions
 *
 * HTML-free and PDF-free: the document models in core/modules/<type>/doc/
 * render what these functions return. Every source produces the same
 * normalized row so the renderer never branches on where rows came from:
 *
 *   fk_product, product_ref, product_label, fk_entrepot, warehouse_ref,
 *   warehouse_label, fk_product_lot, batch, qty, qty_note, loc_id, values,
 *   code, note, status ('assigned' | 'suggested' | 'none')
 *
 * 'suggested' = no assignment for this exact (product, warehouse, lot), but
 * the product has a bin in that warehouse — typical for serials that did not
 * exist before the reception, or lots whose assignment was cleared when stock
 * left on a validated shipment.
 */

dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/lib/binloc_bins.lib.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');

/**
 * The sheet document models binloc provides, keyed by llx_document_model type
 *
 * @return array<string,array{name:string,label:string,const:string,setup_label:string,setup_url:string}>
 */
function binloc_sheet_models()
{
	return array(
		'order' => array(
			'name'        => 'binlocpick',
			'label'       => 'PickSheet',
			'const'       => 'COMMANDE_ADDON_PDF',
			'setup_label' => 'SheetForOrders',
			'setup_url'   => '/admin/order.php',
		),
		'shipping' => array(
			'name'        => 'binlocpickship',
			'label'       => 'PickSheet',
			'const'       => 'EXPEDITION_ADDON_PDF',
			'setup_label' => 'SheetForShipments',
			'setup_url'   => '/admin/expedition.php',
		),
		'reception' => array(
			'name'        => 'binlocplace',
			'label'       => 'PlaceSheet',
			'const'       => 'RECEPTION_ADDON_PDF',
			'setup_label' => 'SheetForReceptions',
			'setup_url'   => '/admin/reception_setup.php',
		),
		'mrp' => array(
			'name'        => 'binlocpickmo',
			'label'       => 'PickSheet',
			'const'       => 'MRP_MO_ADDON_PDF',
			'setup_label' => 'SheetForMos',
			'setup_url'   => '/admin/mrp.php',
		),
	);
}

/**
 * True when a sheet model is activated for a document type in this entity
 *
 * @param  DoliDB $db   Database handler
 * @param  string $type Document model type (order, shipping, reception)
 * @return bool
 */
function binloc_sheet_model_enabled($db, $type)
{
	global $conf;

	$models = binloc_sheet_models();
	if (!isset($models[$type])) {
		return false;
	}
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."document_model";
	$sql .= " WHERE nom = '".$db->escape($models[$type]['name'])."'";
	$sql .= " AND type = '".$db->escape($type)."'";
	$sql .= " AND entity = ".((int) $conf->entity);
	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}
	$found = ($db->num_rows($resql) > 0);
	$db->free($resql);
	return $found;
}

/**
 * New empty sheet row
 *
 * @param  int    $fk_product     Product ID
 * @param  int    $fk_entrepot    Warehouse ID (0 = unknown)
 * @param  int    $fk_product_lot Lot ID (0 = none / not yet created)
 * @param  string $batch          Batch/serial text
 * @param  float  $qty            Quantity to pick or place
 * @return stdClass
 */
function binloc_sheet_row($fk_product, $fk_entrepot, $fk_product_lot, $batch, $qty)
{
	$row = new stdClass();
	$row->fk_product      = (int) $fk_product;
	$row->product_ref     = '';
	$row->product_label   = '';
	$row->fk_entrepot     = (int) $fk_entrepot;
	$row->warehouse_ref   = '';
	$row->warehouse_label = '';
	$row->fk_product_lot  = (int) $fk_product_lot;
	$row->batch           = trim((string) $batch);
	$row->qty             = (float) $qty;
	$row->qty_note        = '';
	$row->loc_id          = 0;
	$row->values          = array();
	$row->code            = '';
	$row->note            = '';
	$row->status          = 'none';
	$row->bins_elsewhere  = array(); // warehouse refs where the product has a bin, when it has none here
	return $row;
}

/**
 * Pick rows for a sales order: where to take the not-yet-shipped quantity from
 *
 * The order does not name lots or bins, so candidates are read from stock:
 * lots with stock (earliest eat-by first) for lot-managed products, warehouse
 * stock otherwise — limited to the order's warehouse when it has one. The
 * remaining quantity is allocated across candidates until covered; a line
 * with too little stock gets a trailing 'none' row for the shortfall.
 *
 * @param  DoliDB   $db    Database handler
 * @param  Commande $order Sales order (lines loaded)
 * @return stdClass[]
 */
function binloc_sheet_rows_from_order($db, $order)
{
	global $langs;

	$rows = array();
	if (empty($order->lines) && method_exists($order, 'fetch_lines')) {
		$order->fetch_lines();
	}
	if (empty($order->lines)) {
		return $rows;
	}

	// Validated/closed shipments only: goods on a draft shipment are still on the shelf
	$order->loadExpeditions(1);

	$product_ids = array();
	foreach ($order->lines as $line) {
		if (!empty($line->fk_product)) {
			$product_ids[] = (int) $line->fk_product;
		}
	}
	$tobatch = binloc_sheet_products_tobatch($db, $product_ids);

	$fk_warehouse = empty($order->fk_warehouse) ? 0 : (int) $order->fk_warehouse;

	foreach ($order->lines as $line) {
		$fk_product = empty($line->fk_product) ? 0 : (int) $line->fk_product;
		if ($fk_product <= 0 || (isset($line->product_type) && (int) $line->product_type !== 0)) {
			continue;
		}
		$line_id   = !empty($line->id) ? (int) $line->id : (int) $line->rowid;
		$shipped   = isset($order->expeditions[$line_id]) ? (float) $order->expeditions[$line_id] : 0.0;
		$remaining = (float) $line->qty - $shipped;
		if ($remaining <= 0) {
			continue;
		}

		$candidates = binloc_sheet_stock_candidates($db, $fk_product, !empty($tobatch[$fk_product]), $fk_warehouse);
		binloc_sheet_allocate($rows, $fk_product, $remaining, $candidates, $fk_warehouse);
	}

	binloc_sheet_attach_locations($db, $rows);
	return $rows;
}

/**
 * Pick rows for a shipment: the exact warehouses and lots the shipment names
 *
 * Read straight from expeditiondet / expeditiondet_batch rather than
 * Expedition::fetch_lines(), which merges multi-warehouse splits of one
 * order line. Kit sub-lines (fk_parent) are skipped.
 *
 * @param  DoliDB     $db  Database handler
 * @param  Expedition $exp Shipment
 * @return stdClass[]
 */
function binloc_sheet_rows_from_shipment($db, $exp)
{
	$rows = array();
	if (empty($exp->id)) {
		return $rows;
	}

	$sql = "SELECT ed.rowid as line_id, ed.qty as line_qty, ed.fk_entrepot,";
	$sql .= " p.rowid as fk_product, p.fk_product_type,";
	$sql .= " eb.rowid as batch_id, eb.batch, eb.qty as batch_qty, eb.fk_warehouse as batch_wh,";
	$sql .= " pl.rowid as fk_product_lot";
	$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet as ed";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."commandedet as cd ON (cd.rowid = ed.fk_elementdet AND ed.element_type = 'commande')";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = COALESCE(ed.fk_product, cd.fk_product)";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."expeditiondet_batch as eb ON eb.fk_expeditiondet = ed.rowid";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as pl ON (pl.fk_product = p.rowid AND pl.batch = eb.batch AND pl.entity IN (".getEntity('stock')."))";
	$sql .= " WHERE ed.fk_expedition = ".((int) $exp->id);
	$sql .= " AND (ed.fk_parent IS NULL OR ed.fk_parent = 0)";
	$sql .= " ORDER BY ed.rang ASC, ed.rowid ASC, eb.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog('binloc_sheet_rows_from_shipment '.$db->lasterror(), LOG_ERR);
		return $rows;
	}
	while ($obj = $db->fetch_object($resql)) {
		if ((int) $obj->fk_product_type !== 0) {
			continue;
		}
		if (!empty($obj->batch_id)) {
			$wh  = !empty($obj->batch_wh) ? (int) $obj->batch_wh : (int) $obj->fk_entrepot;
			$row = binloc_sheet_row($obj->fk_product, $wh, (int) $obj->fk_product_lot, (string) $obj->batch, (float) $obj->batch_qty);
		} else {
			$row = binloc_sheet_row($obj->fk_product, (int) $obj->fk_entrepot, 0, '', (float) $obj->line_qty);
		}
		if ($row->qty <= 0) {
			continue;
		}
		$rows[] = $row;
	}
	$db->free($resql);

	binloc_sheet_attach_locations($db, $rows);
	return $rows;
}

/**
 * True when a shipment's stock has already left the warehouse, so lot
 * assignments may have been cleared by the binloc stock-movement trigger
 *
 * @param  Expedition $exp Shipment
 * @return bool
 */
function binloc_sheet_shipment_stock_moved($exp)
{
	$status = (int) $exp->status;
	if ($status > 0 && getDolGlobalString('STOCK_CALCULATE_ON_SHIPMENT')) {
		return true;
	}
	return ($status == 2 && getDolGlobalString('STOCK_CALCULATE_ON_SHIPMENT_CLOSE'));
}

/**
 * Place rows for a reception: where each received line goes
 *
 * @param  DoliDB    $db  Database handler
 * @param  Reception $rec Reception
 * @return stdClass[]
 */
function binloc_sheet_rows_from_reception($db, $rec)
{
	$rows = array();
	if (empty($rec->id)) {
		return $rows;
	}

	// Read receptiondet_batch directly: how Reception::fetch_lines() exposes
	// the batch on its line objects varies between Dolibarr versions
	$sql = "SELECT rc.fk_product, rc.fk_entrepot, rc.batch, rc.qty, p.fk_product_type, pl.rowid as fk_product_lot";
	$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch as rc";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = rc.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as pl ON (pl.fk_product = rc.fk_product AND pl.batch = rc.batch AND pl.entity IN (".getEntity('stock')."))";
	$sql .= " WHERE rc.fk_reception = ".((int) $rec->id);
	// No rc.rang: that column only exists from Dolibarr 23 (v22 would fail the whole query)
	$sql .= " ORDER BY rc.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog('binloc_sheet_rows_from_reception '.$db->lasterror(), LOG_ERR);
		return $rows;
	}
	while ($obj = $db->fetch_object($resql)) {
		if ((int) $obj->fk_product_type !== 0 || (float) $obj->qty <= 0) {
			continue;
		}
		$batch = trim((string) $obj->batch);
		$rows[] = binloc_sheet_row($obj->fk_product, (int) $obj->fk_entrepot, ($batch !== '' ? (int) $obj->fk_product_lot : 0), $batch, (float) $obj->qty);
	}
	$db->free($resql);

	binloc_sheet_attach_locations($db, $rows);
	return $rows;
}

/**
 * Pick rows for a manufacturing order: materials still to consume
 *
 * Like an order, the MO names no lots or bins, so each line's remaining
 * quantity is allocated from stock (earliest eat-by first for lots), limited
 * to the line's warehouse, else the MO's, else any warehouse.
 *
 * @param  DoliDB $db Database handler
 * @param  Mo     $mo Manufacturing order
 * @return stdClass[]
 */
function binloc_sheet_rows_from_mo_materials($db, $mo)
{
	$rows = array();
	foreach (binloc_sheet_mo_open_lines($db, $mo, 'toconsume', 'consumed') as $line) {
		$candidates = binloc_sheet_stock_candidates($db, $line->fk_product, $line->lot_managed, $line->fk_warehouse);
		binloc_sheet_allocate($rows, $line->fk_product, $line->remaining, $candidates, $line->fk_warehouse);
	}

	binloc_sheet_attach_locations($db, $rows);
	return $rows;
}

/**
 * Place rows for a manufacturing order: finished goods still to produce
 *
 * Serials don't exist until they are produced, so a lot-managed product can
 * only be matched to a bin its other lots already use ('suggested').
 *
 * @param  DoliDB $db Database handler
 * @param  Mo     $mo Manufacturing order
 * @return stdClass[]
 */
function binloc_sheet_rows_from_mo_output($db, $mo)
{
	$rows = array();
	foreach (binloc_sheet_mo_open_lines($db, $mo, 'toproduce', 'produced') as $line) {
		$row = binloc_sheet_row($line->fk_product, $line->fk_warehouse, 0, '', $line->remaining);
		$row->lot_managed = $line->lot_managed;
		$rows[] = $row;
	}

	binloc_sheet_attach_locations($db, $rows);
	return $rows;
}

/**
 * Open lines of a manufacturing order for one role, with the quantity left
 *
 * Consumption and production are recorded as child lines ('consumed' /
 * 'produced') pointing at the planned line through fk_mrp_production, so
 * what is left is the planned quantity minus their sum. Service lines and
 * lines that don't move stock (disable_stock_change) are skipped. The
 * warehouse is the line's, else the MO's, else 0 (any).
 *
 * @param  DoliDB $db        Database handler
 * @param  Mo     $mo        Manufacturing order
 * @param  string $role      Planned role: 'toconsume' or 'toproduce'
 * @param  string $done_role Recorded role: 'consumed' or 'produced'
 * @return stdClass[]        {fk_product, fk_warehouse, remaining, lot_managed}
 */
function binloc_sheet_mo_open_lines($db, $mo, $role, $done_role)
{
	$out = array();
	if (empty($mo->id)) {
		return $out;
	}

	$sql = "SELECT l.fk_product, l.fk_warehouse, l.qty, l.disable_stock_change, p.fk_product_type, p.tobatch,";
	$sql .= " (SELECT SUM(d.qty) FROM ".MAIN_DB_PREFIX."mrp_production as d";
	$sql .= " WHERE d.fk_mrp_production = l.rowid AND d.role = '".$db->escape($done_role)."') as qty_done";
	$sql .= " FROM ".MAIN_DB_PREFIX."mrp_production as l";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product";
	$sql .= " WHERE l.fk_mo = ".((int) $mo->id);
	$sql .= " AND l.role = '".$db->escape($role)."'";
	$sql .= " ORDER BY l.position ASC, l.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog('binloc_sheet_mo_open_lines '.$db->lasterror(), LOG_ERR);
		return $out;
	}
	$mo_warehouse = empty($mo->fk_warehouse) ? 0 : (int) $mo->fk_warehouse;
	while ($obj = $db->fetch_object($resql)) {
		if ((int) $obj->fk_product_type !== 0 || !empty($obj->disable_stock_change)) {
			continue;
		}
		$remaining = (float) $obj->qty - (float) $obj->qty_done;
		if ($remaining <= 0) {
			continue;
		}
		$line = new stdClass();
		$line->fk_product   = (int) $obj->fk_product;
		$line->fk_warehouse = !empty($obj->fk_warehouse) ? (int) $obj->fk_warehouse : $mo_warehouse;
		$line->remaining    = $remaining;
		$line->lot_managed  = isModEnabled('productbatch') && (int) $obj->tobatch > 0;
		$out[] = $line;
	}
	$db->free($resql);
	return $out;
}

/**
 * Split a quantity across stock candidates, in their order, into sheet rows
 *
 * Takes from each candidate until the quantity is covered; notes how much
 * stock each spot holds and how many other spots were not needed. A
 * shortfall becomes a trailing row: in the warehouse the candidates were
 * limited to (so it reads "no stock in this warehouse", not "no stock"), or
 * with no warehouse when every warehouse was searched.
 *
 * @param  stdClass[] $rows         Sheet rows, appended to
 * @param  int        $fk_product   Product ID
 * @param  float      $qty          Quantity to allocate
 * @param  stdClass[] $candidates   From binloc_sheet_stock_candidates()
 * @param  int        $fk_warehouse Warehouse the candidates were limited to (0 = all)
 * @return void
 */
function binloc_sheet_allocate(&$rows, $fk_product, $qty, $candidates, $fk_warehouse = 0)
{
	global $langs;

	$left = $qty;
	$used = 0;
	foreach ($candidates as $cand) {
		if ($left <= 0) {
			break;
		}
		$take = min($left, (float) $cand->qty);
		$row = binloc_sheet_row($fk_product, $cand->fk_entrepot, $cand->fk_product_lot, $cand->batch, $take);
		$row->qty_note = $langs->trans('SheetStockHere', price2num($cand->qty, 'MS'));
		$rows[] = $row;
		$left -= $take;
		$used++;
	}

	$others = count($candidates) - $used;
	if ($used > 0 && $others > 0) {
		$last = $rows[count($rows) - 1];
		$last->qty_note = implode(' · ', array($last->qty_note, $langs->trans('SheetOtherLocations', $others)));
	}

	if ($left > 0) {
		$row = binloc_sheet_row($fk_product, (int) $fk_warehouse, 0, '', $left);
		if ($fk_warehouse > 0) {
			$row->qty_note = $langs->trans($used > 0 ? 'SheetShortStockHere' : 'SheetNoStockHere');
		} else {
			$row->qty_note = $langs->trans($used > 0 ? 'SheetShortStock' : 'SheetNoStock');
		}
		$rows[] = $row;
	}
}

/**
 * tobatch flag per product (products without batch management map to 0)
 *
 * @param  DoliDB $db          Database handler
 * @param  int[]  $product_ids Product IDs
 * @return array<int,int>
 */
function binloc_sheet_products_tobatch($db, $product_ids)
{
	$out = array();
	$product_ids = array_unique(array_filter(array_map('intval', $product_ids)));
	if (empty($product_ids) || !isModEnabled('productbatch')) {
		return $out;
	}
	$sql = "SELECT rowid, tobatch FROM ".MAIN_DB_PREFIX."product WHERE rowid IN (".implode(',', $product_ids).")";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[(int) $obj->rowid] = (int) $obj->tobatch;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Stock a product can be picked from: one entry per lot (earliest eat-by
 * first) or per warehouse
 *
 * @param  DoliDB $db           Database handler
 * @param  int    $fk_product   Product ID
 * @param  bool   $lots         Product is lot/serial managed
 * @param  int    $fk_warehouse Limit to this warehouse (0 = all)
 * @return stdClass[]           {fk_entrepot, fk_product_lot, batch, qty}
 */
function binloc_sheet_stock_candidates($db, $fk_product, $lots, $fk_warehouse = 0)
{
	$out = array();

	if ($lots) {
		$sql = "SELECT ps.fk_entrepot, pb.batch, pb.qty, pl.rowid as fk_product_lot, pl.eatby";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as pl ON (pl.fk_product = ps.fk_product AND pl.batch = pb.batch AND pl.entity IN (".getEntity('stock')."))";
		$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
		$sql .= " AND pb.qty > 0";
		$sql .= " AND e.entity IN (".getEntity('stock').")";
		if ($fk_warehouse > 0) {
			$sql .= " AND ps.fk_entrepot = ".((int) $fk_warehouse);
		}
		$sql .= " ORDER BY (pl.eatby IS NULL) ASC, pl.eatby ASC, pb.batch ASC";
	} else {
		$sql = "SELECT ps.fk_entrepot, ps.reel as qty, e.ref";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_stock as ps";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
		$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
		$sql .= " AND ps.reel > 0";
		$sql .= " AND e.entity IN (".getEntity('stock').")";
		if ($fk_warehouse > 0) {
			$sql .= " AND ps.fk_entrepot = ".((int) $fk_warehouse);
		}
		$sql .= " ORDER BY e.ref ASC";
	}

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog('binloc_sheet_stock_candidates '.$db->lasterror(), LOG_ERR);
		return $out;
	}
	while ($obj = $db->fetch_object($resql)) {
		$cand = new stdClass();
		$cand->fk_entrepot    = (int) $obj->fk_entrepot;
		$cand->fk_product_lot = $lots ? (int) $obj->fk_product_lot : 0;
		$cand->batch          = $lots ? (string) $obj->batch : '';
		$cand->qty            = (float) $obj->qty;
		$out[] = $cand;
	}
	$db->free($resql);
	return $out;
}

/**
 * Attach the bin assignment to each row (loc_id, values, note, status)
 *
 * Exact match on (product, warehouse, lot) = 'assigned'. Otherwise, in the
 * same warehouse, the product-level assignment or any lot of that product =
 * 'suggested'. A batch whose lot record does not exist yet (new serial on a
 * draft reception) can only be suggested.
 *
 * @param  DoliDB     $db   Database handler
 * @param  stdClass[] $rows Sheet rows, modified in place
 * @return void
 */
function binloc_sheet_attach_locations($db, $rows)
{
	$loc = new BinlocProductLocation($db);

	$headers = array();
	foreach ($rows as $row) {
		if ($row->fk_product <= 0 || $row->fk_entrepot <= 0) {
			continue;
		}
		$id = 0;
		$status = 'none';
		$exact_possible = ($row->batch === '' || $row->fk_product_lot > 0);
		if ($exact_possible) {
			$id = $loc->findRowId($row->fk_product, $row->fk_entrepot, $row->fk_product_lot);
			if ($id > 0) {
				$status = 'assigned';
			}
		}
		if ($id <= 0 && ($row->batch !== '' || $row->fk_product_lot > 0)) {
			$id = $loc->findRowId($row->fk_product, $row->fk_entrepot, 0);
			if ($id > 0) {
				$status = 'suggested';
			}
		}
		// Also for lot-managed output not produced yet (no batch): its other lots' bin
		if ($id <= 0 && ($row->batch !== '' || !empty($row->lot_managed))) {
			$id = binloc_sheet_any_lot_location($db, $row->fk_product, $row->fk_entrepot);
			if ($id > 0) {
				$status = 'suggested';
			}
		}
		if ($id > 0) {
			$row->loc_id = $id;
			$row->status = $status;
			$header = new stdClass();
			$header->rowid  = $id;
			$header->target = $row;
			$headers[] = $header;
		}
	}

	// Rows with no bin in their warehouse: name the warehouses where the
	// product does have one, so "No bin assigned" isn't mistaken for "no bin anywhere"
	$pids = array();
	foreach ($rows as $row) {
		if ($row->status === 'none' && $row->fk_product > 0) {
			$pids[(int) $row->fk_product] = true;
		}
	}
	if (!empty($pids)) {
		$elsewhere = array();
		$sql = "SELECT DISTINCT pl.fk_product, pl.fk_entrepot, e.ref";
		$sql .= " FROM ".MAIN_DB_PREFIX."binloc_product_location as pl";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = pl.fk_entrepot";
		$sql .= " WHERE pl.fk_product IN (".implode(',', array_keys($pids)).")";
		$sql .= " AND pl.entity IN (".getEntity('stock').")";
		$sql .= " ORDER BY e.ref ASC";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$elsewhere[(int) $obj->fk_product][(int) $obj->fk_entrepot] = (string) $obj->ref;
			}
			$db->free($resql);
		}
		foreach ($rows as $row) {
			if ($row->status !== 'none' || empty($elsewhere[$row->fk_product])) {
				continue;
			}
			foreach ($elsewhere[$row->fk_product] as $wh => $ref) {
				if ($wh !== $row->fk_entrepot) {
					$row->bins_elsewhere[] = $ref;
				}
			}
		}
	}

	if (empty($headers)) {
		return;
	}

	$ids = array();
	foreach ($headers as $h) {
		$ids[] = (int) $h->rowid;
	}
	$notes = array();
	$sql = "SELECT rowid, note FROM ".MAIN_DB_PREFIX."binloc_product_location WHERE rowid IN (".implode(',', array_unique($ids)).")";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$notes[(int) $obj->rowid] = (string) $obj->note;
		}
		$db->free($resql);
	}

	$loc->loadValuesForRows($headers);
	foreach ($headers as $h) {
		$h->target->values = $h->values;
		$h->target->note   = isset($notes[$h->rowid]) ? $notes[$h->rowid] : '';
	}
}

/**
 * Any lot-level assignment of a product in a warehouse (first created)
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_product  Product ID
 * @param  int    $fk_entrepot Warehouse ID
 * @return int                 rowid or 0
 */
function binloc_sheet_any_lot_location($db, $fk_product, $fk_entrepot)
{
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."binloc_product_location";
	$sql .= " WHERE fk_product = ".((int) $fk_product);
	$sql .= " AND fk_entrepot = ".((int) $fk_entrepot);
	$sql .= " AND fk_product_lot > 0";
	$sql .= " AND entity IN (".getEntity('stock').")";
	$sql .= " ORDER BY rowid ASC";
	$sql .= $db->plimit(1);
	$resql = $db->query($sql);
	if (!$resql) {
		return 0;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	return $obj ? (int) $obj->rowid : 0;
}

/**
 * Fill display fields, build bin codes, sort in walk order and group
 *
 * Codes are built exactly like label titles (warehouse prefix when the
 * warehouse's label layout shows it, then level values in position order,
 * joined with the layout's separator), so the sheet and the bin label match.
 *
 * Walk order: warehouse prefix (or ref), then each level by option position
 * (dropdown/letter levels) or natural text order, unassigned rows last.
 *
 * @param  DoliDB     $db    Database handler
 * @param  stdClass[] $rows  Sheet rows
 * @param  bool       $split One group per warehouse (else a single group)
 * @return stdClass[]        Groups {fk_entrepot (-1 = combined), warehouse_ref, warehouse_label, rows}
 */
function binloc_sheet_finalize($db, $rows, $split)
{
	// Products
	$pids = array();
	$whs  = array();
	foreach ($rows as $row) {
		$pids[] = $row->fk_product;
		if ($row->fk_entrepot > 0) {
			$whs[] = $row->fk_entrepot;
		}
	}
	$pids = array_unique(array_filter($pids));
	$whs  = array_values(array_unique($whs));

	$products = array();
	if (!empty($pids)) {
		$resql = $db->query("SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."product WHERE rowid IN (".implode(',', array_map('intval', $pids)).")");
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$products[(int) $obj->rowid] = $obj;
			}
			$db->free($resql);
		}
	}

	// Warehouses: ref, label, prefix, separator, levels
	$warehouses = array();
	if (!empty($whs)) {
		$resql = $db->query("SELECT rowid, ref, lieu FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid IN (".implode(',', array_map('intval', $whs)).")");
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$warehouses[(int) $obj->rowid] = $obj;
			}
			$db->free($resql);
		}
	}
	$levelObj = new BinlocWarehouseLevel($db);
	$levels_by_wh = $levelObj->fetchByWarehouses($whs);

	$wh_info = array();
	foreach ($whs as $wh) {
		$layout = binloc_get_label_layout($db, $wh);
		$prefix = !empty($layout->show_warehouse) ? binloc_get_warehouse_code($db, $wh) : '';
		$levels = isset($levels_by_wh[$wh]) ? $levels_by_wh[$wh] : array();
		$opt_pos = array();
		foreach ($levels as $lid => $cfg) {
			foreach ($cfg->options as $opt) {
				$opt_pos[(int) $opt->id] = (int) $opt->position;
			}
		}
		$info = new stdClass();
		$info->ref     = isset($warehouses[$wh]) ? (string) $warehouses[$wh]->ref : '';
		$info->label   = isset($warehouses[$wh]) ? (string) $warehouses[$wh]->lieu : '';
		$info->prefix  = $prefix;
		$info->sep     = (string) $layout->code_sep;
		$info->levels  = $levels;
		$info->chain   = binloc_level_chain($levels);
		$info->opt_pos = $opt_pos;
		$wh_info[$wh] = $info;
	}

	foreach ($rows as $row) {
		if (isset($products[$row->fk_product])) {
			$row->product_ref   = (string) $products[$row->fk_product]->ref;
			$row->product_label = (string) $products[$row->fk_product]->label;
		}
		$row->sort = array();
		if (!isset($wh_info[$row->fk_entrepot])) {
			$row->sort[] = array(1, '');
			continue;
		}
		$info = $wh_info[$row->fk_entrepot];
		$row->warehouse_ref   = $info->ref;
		$row->warehouse_label = $info->label;

		$parts = array();
		$level_sort = array();
		foreach ($info->chain as $lid) {
			$entry = isset($row->values[$lid]) ? $row->values[$lid] : null;
			if (!binloc_value_present($entry)) {
				$level_sort[] = array(1, '');
				continue;
			}
			$parts[] = (string) $entry->display;
			if (!empty($entry->fk_option) && isset($info->opt_pos[(int) $entry->fk_option])) {
				$level_sort[] = array(0, $info->opt_pos[(int) $entry->fk_option]);
			} else {
				$level_sort[] = array(0, (string) $entry->display);
			}
		}
		if (!empty($parts) && $info->prefix !== '') {
			array_unshift($parts, $info->prefix);
		}
		$row->code = implode($info->sep, $parts);

		$row->sort[] = array(0, $info->prefix !== '' ? $info->prefix : $info->ref);
		$row->sort[] = array(0, $info->ref);
		$row->sort[] = array(empty($parts) ? 1 : 0, '');
		foreach ($level_sort as $part) {
			$row->sort[] = $part;
		}
	}

	usort($rows, 'binloc_sheet_compare_rows');

	$groups = array();
	if ($split) {
		foreach ($rows as $row) {
			$key = $row->fk_entrepot > 0 ? $row->fk_entrepot : 0;
			if (!isset($groups[$key])) {
				$group = new stdClass();
				$group->fk_entrepot     = $key;
				$group->warehouse_ref   = $row->warehouse_ref;
				$group->warehouse_label = $row->warehouse_label;
				$group->rows            = array();
				$groups[$key] = $group;
			}
			$groups[$key]->rows[] = $row;
		}
		// Rows with no warehouse (order lines without stock) go last
		if (isset($groups[0])) {
			$none = $groups[0];
			unset($groups[0]);
			$groups[0] = $none;
		}
	} else {
		$group = new stdClass();
		$group->fk_entrepot     = -1;
		$group->warehouse_ref   = '';
		$group->warehouse_label = '';
		$group->rows            = $rows;
		$groups[] = $group;
	}

	return array_values($groups);
}

/**
 * usort comparator for sheet rows: ->sort parts element-wise, then product and batch
 *
 * @param  stdClass $a Row
 * @param  stdClass $b Row
 * @return int
 */
function binloc_sheet_compare_rows($a, $b)
{
	$n = max(count($a->sort), count($b->sort));
	for ($i = 0; $i < $n; $i++) {
		$pa = isset($a->sort[$i]) ? $a->sort[$i] : array(1, '');
		$pb = isset($b->sort[$i]) ? $b->sort[$i] : array(1, '');
		if ($pa[0] !== $pb[0]) {
			return $pa[0] - $pb[0];
		}
		if (is_int($pa[1]) && is_int($pb[1])) {
			$cmp = $pa[1] - $pb[1];
		} else {
			$cmp = strnatcasecmp((string) $pa[1], (string) $pb[1]);
		}
		if ($cmp !== 0) {
			return $cmp;
		}
	}
	$cmp = strnatcasecmp($a->product_ref, $b->product_ref);
	return $cmp !== 0 ? $cmp : strnatcasecmp($a->batch, $b->batch);
}
