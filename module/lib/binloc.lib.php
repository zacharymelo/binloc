<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/binloc.lib.php
 * \ingroup binloc
 * \brief   Helper functions for Binloc module
 */

/**
 * Build admin page tab header
 *
 * @return array Array of tab definitions
 */
function binloc_admin_prepare_head()
{
	global $langs, $conf;

	$langs->load('binloc@binloc');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/binloc/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/binloc/admin/warehouse_levels.php', 1);
	$head[$h][1] = $langs->trans('WarehouseLevels');
	$head[$h][2] = 'warehouselevels';
	$h++;

	return $head;
}

/**
 * Print the shared JS/CSS assets (once per page)
 *
 * @return void
 */
function binloc_print_assets()
{
	static $printed = false;
	if ($printed) {
		return;
	}
	$printed = true;

	$v = '2.1.0';
	print '<link rel="stylesheet" href="'.dol_buildpath('/binloc/css/binloc.css', 1).'?v='.$v.'">'."\n";
	print '<script src="'.dol_buildpath('/binloc/js/binloc.js', 1).'?v='.$v.'"></script>'."\n";
	print '<script>Binloc.init({ajaxBase: "'.dol_escape_js(dol_buildpath('/binloc/ajax/', 1)).'", token: "'.newToken().'"});</script>'."\n";
}

/**
 * Get warehouse level configuration (shorthand)
 *
 * @param  DoliDB $db           Database handler
 * @param  int    $fk_entrepot  Warehouse ID
 * @return array                level rowid => stdClass config map (position order)
 */
function binloc_get_warehouse_levels($db, $fk_entrepot)
{
	dol_include_once('/binloc/class/binlocwarehouselevel.class.php');

	$lvl = new BinlocWarehouseLevel($db);
	return $lvl->fetchByWarehouse($fk_entrepot);
}

/**
 * Render a level input element based on its configured datatype
 *
 * Produces a text input, number input, or select dropdown. This is the ONLY
 * place level inputs are rendered — pages print it server-side and the
 * levels_get.php ajax endpoint returns it for client-side warehouse swaps.
 *
 * Input naming: {prefix}binloc_level{level_rowid}. List selects submit the
 * option rowid. A current list value whose option is deactivated (or was
 * created by migration as a legacy stray) is rendered as an extra selected
 * "(legacy)" option — it is never silently blanked.
 *
 * @param  stdClass      $level_cfg Level config from fetchByWarehouse (id, label, datatype, options)
 * @param  string        $prefix    Input name prefix ('' or e.g. "row7_")
 * @param  stdClass|null $current   Current value entry {fk_option, value, display} or null
 * @param  string        $css_class Optional CSS class override
 * @param  string        $extra_attrs Optional extra HTML attributes
 * @return string        HTML input element
 */
function binloc_render_level_input($level_cfg, $prefix = '', $current = null, $css_class = 'flat width100 binloc-level-input', $extra_attrs = '')
{
	global $langs;

	$input_name = $prefix.'binloc_level'.(int) $level_cfg->id;
	$label      = isset($level_cfg->label) ? $level_cfg->label : '';
	$datatype   = isset($level_cfg->datatype) ? $level_cfg->datatype : 'text';

	$attrs = ' data-level="'.(int) $level_cfg->id.'" data-datatype="'.dol_escape_htmltag($datatype).'"';
	$attrs .= ($extra_attrs !== '' ? ' '.$extra_attrs : '');

	if ($datatype === 'list') {
		$current_opt = ($current && !empty($current->fk_option)) ? (int) $current->fk_option : 0;
		$html = '<select name="'.dol_escape_htmltag($input_name).'" class="'.dol_escape_htmltag($css_class).'" aria-label="'.dol_escape_htmltag($label).'"'.$attrs.'>';
		$html .= '<option value="">'.dol_escape_htmltag($label).'…</option>';
		$found = false;
		foreach ($level_cfg->options as $opt) {
			$is_current = ($current_opt > 0 && (int) $opt->id === $current_opt);
			if (!$opt->active && !$is_current) {
				continue;
			}
			$found = $found || $is_current;
			$sel = $is_current ? ' selected' : '';
			$suffix = (!$opt->active ? ' ('.$langs->trans('LegacyValue').')' : '');
			$html .= '<option value="'.(int) $opt->id.'"'.$sel.'>'.dol_escape_htmltag($opt->value.$suffix).'</option>';
		}
		if ($current_opt > 0 && !$found && $current && $current->display !== null) {
			// Option row vanished entirely (should not happen — FK protects it) but never blank a stored value
			$html .= '<option value="'.$current_opt.'" selected>'.dol_escape_htmltag($current->display.' ('.$langs->trans('LegacyValue').')').'</option>';
		}
		$html .= '</select>';
		return $html;
	}

	$current_val = ($current && $current->display !== null) ? $current->display : '';
	$type = ($datatype === 'number') ? 'number' : 'text';
	return '<input type="'.$type.'" name="'.dol_escape_htmltag($input_name).'" class="'.dol_escape_htmltag($css_class).'" value="'.dol_escape_htmltag($current_val).'" placeholder="'.dol_escape_htmltag($label).'" aria-label="'.dol_escape_htmltag($label).'"'.$attrs.'>';
}

/**
 * Render the full set of level inputs for a warehouse
 *
 * @param  array  $level_cfgs Level configs keyed by rowid (fetchByWarehouse output)
 * @param  string $prefix     Input name prefix
 * @param  array  $values     Current values keyed by level rowid ({fk_option, value, display})
 * @param  string $css_class  Optional CSS class override
 * @return string             HTML fragment
 */
function binloc_render_level_inputs($level_cfgs, $prefix = '', $values = array(), $css_class = 'flat width100 binloc-level-input')
{
	$html = '';
	foreach ($level_cfgs as $id => $cfg) {
		$current = isset($values[$id]) ? $values[$id] : null;
		$html .= binloc_render_level_input($cfg, $prefix, $current, $css_class).' ';
	}
	return $html;
}

/**
 * Collect posted level values for a warehouse's levels
 *
 * @param  array  $level_cfgs Level configs keyed by rowid
 * @param  string $prefix     Input name prefix used at render time
 * @return array              level rowid => raw posted value
 */
function binloc_get_posted_level_values($level_cfgs, $prefix = '')
{
	$raw = array();
	foreach ($level_cfgs as $id => $cfg) {
		$raw[$id] = GETPOST($prefix.'binloc_level'.$id, 'alphanohtml');
	}
	return $raw;
}

/**
 * Get formatted location string for a product in a warehouse (no-lot row)
 *
 * @param  DoliDB $db           Database handler
 * @param  int    $fk_product   Product ID
 * @param  int    $fk_entrepot  Warehouse ID
 * @return string               Formatted location or empty string
 */
function binloc_format_location($db, $fk_product, $fk_entrepot)
{
	dol_include_once('/binloc/class/binlocproductlocation.class.php');

	$loc = new BinlocProductLocation($db);
	$result = $loc->fetchByProductWarehouse($fk_product, $fk_entrepot);
	if ($result <= 0) {
		return '';
	}

	$levels = binloc_get_warehouse_levels($db, $fk_entrepot);
	if (empty($levels)) {
		return '';
	}

	return $loc->getFormattedLocation($levels);
}

/**
 * Get all active warehouses (no parent filter — all warehouses, for the level config page)
 *
 * @param  DoliDB $db Database handler
 * @return array      Array of warehouse objects (rowid, ref, lieu, stock)
 */
function binloc_get_warehouses($db)
{
	$warehouses = array();

	$sql = "SELECT e.rowid, e.ref, e.lieu, e.statut,";
	$sql .= " SUM(ps.reel) as stock";
	$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_entrepot = e.rowid";
	$sql .= " WHERE e.entity IN (".getEntity('stock').")";
	$sql .= " AND e.statut = 1";
	$sql .= " GROUP BY e.rowid, e.ref, e.lieu, e.statut";
	$sql .= " ORDER BY e.ref ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $warehouses;
	}

	while ($obj = $db->fetch_object($resql)) {
		$warehouses[] = $obj;
	}
	$db->free($resql);

	return $warehouses;
}

/**
 * Render a warehouse <select> (shared across pages)
 *
 * @param  DoliDB $db          Database handler
 * @param  string $name        Input name
 * @param  int    $selected    Selected warehouse rowid
 * @param  string $css_class   CSS class
 * @param  string $extra_attrs Extra HTML attributes
 * @param  bool   $with_empty  Prepend an empty option
 * @return string              HTML select
 */
function binloc_render_warehouse_select($db, $name, $selected = 0, $css_class = 'flat minwidth200', $extra_attrs = '', $with_empty = true)
{
	global $langs;

	$html = '<select name="'.dol_escape_htmltag($name).'" class="'.dol_escape_htmltag($css_class).'"'.($extra_attrs !== '' ? ' '.$extra_attrs : '').'>';
	if ($with_empty) {
		$html .= '<option value="">'.dol_escape_htmltag($langs->trans('SelectWarehouse')).'</option>';
	}
	foreach (binloc_get_warehouses($db) as $wh) {
		$sel = ((int) $wh->rowid === (int) $selected) ? ' selected' : '';
		$label = $wh->ref.($wh->lieu ? ' — '.$wh->lieu : '');
		$html .= '<option value="'.(int) $wh->rowid.'"'.$sel.'>'.dol_escape_htmltag($label).'</option>';
	}
	$html .= '</select>';
	return $html;
}

/**
 * Get all products with stock in a specific warehouse (for bulk assign)
 *
 * Each row: fk_product, ref, label, stock, loc_rowid (0 when unassigned),
 * note, location (formatted string), values (level rowid => value entry).
 *
 * @param  DoliDB $db           Database handler
 * @param  int    $fk_entrepot  Warehouse ID
 * @param  string $search       Optional ref/label search filter
 * @param  string $sortfield    Sort field
 * @param  string $sortorder    Sort order
 * @param  int    $limit        Max rows
 * @param  int    $offset       Offset
 * @return array                Array of product objects with stock and location data
 */
function binloc_get_products_in_warehouse($db, $fk_entrepot, $search = '', $sortfield = 'p.ref', $sortorder = 'ASC', $limit = 0, $offset = 0)
{
	dol_include_once('/binloc/class/binlocproductlocation.class.php');

	$products = array();

	$sql = "SELECT p.rowid as fk_product, p.ref, p.label,";
	$sql .= " ps.reel as stock,";
	$sql .= " pl.rowid as loc_rowid,";
	$sql .= " pl.note,";
	$sql .= " (SELECT GROUP_CONCAT(CONCAT(w.label, ': ', COALESCE(o.value, v.value))";
	$sql .= "   ORDER BY w.position ASC, w.rowid ASC SEPARATOR ' / ')";
	$sql .= "   FROM ".MAIN_DB_PREFIX."binloc_location_value as v";
	$sql .= "   INNER JOIN ".MAIN_DB_PREFIX."binloc_warehouse_levels as w ON w.rowid = v.fk_level";
	$sql .= "   LEFT JOIN ".MAIN_DB_PREFIX."binloc_level_options as o ON o.rowid = v.fk_option";
	$sql .= "   WHERE v.fk_location = pl.rowid) as location";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_stock as ps";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = ps.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_product_location as pl";
	$sql .= "   ON (pl.fk_product = ps.fk_product AND pl.fk_entrepot = ps.fk_entrepot";
	$sql .= "   AND pl.fk_product_lot = 0";
	$sql .= "   AND pl.entity IN (".getEntity('stock')."))";
	$sql .= " WHERE ps.fk_entrepot = ".(int) $fk_entrepot;
	$sql .= " AND ps.reel > 0";
	$sql .= " AND p.entity IN (".getEntity('product').")";

	if (!empty($search)) {
		$sql .= " AND (p.ref LIKE '%".$db->escape($search)."%'";
		$sql .= " OR p.label LIKE '%".$db->escape($search)."%')";
	}

	$sql .= $db->order($sortfield, $sortorder);
	if ($limit > 0) {
		$sql .= $db->plimit($limit, $offset);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return $products;
	}

	while ($obj = $db->fetch_object($resql)) {
		$obj->loc_rowid = $obj->loc_rowid ? (int) $obj->loc_rowid : 0;
		$obj->location  = (string) $obj->location;
		$obj->values    = array();
		$products[] = $obj;
	}
	$db->free($resql);

	// Attach per-level values for rows that have an assignment
	$located = array();
	foreach ($products as $obj) {
		if ($obj->loc_rowid > 0) {
			$row = new stdClass();
			$row->rowid = $obj->loc_rowid;
			$row->target = $obj;
			$located[] = $row;
		}
	}
	if (!empty($located)) {
		$loc = new BinlocProductLocation($db);
		$loc->loadValuesForRows($located);
		foreach ($located as $row) {
			$row->target->values = $row->values;
		}
	}

	return $products;
}

/**
 * Count products with stock in a specific warehouse
 *
 * @param  DoliDB $db           Database handler
 * @param  int    $fk_entrepot  Warehouse ID
 * @param  string $search       Optional search filter
 * @return int                  Count
 */
function binloc_count_products_in_warehouse($db, $fk_entrepot, $search = '')
{
	$sql = "SELECT COUNT(*) as nb";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_stock as ps";
	if (!empty($search)) {
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = ps.fk_product";
	}
	$sql .= " WHERE ps.fk_entrepot = ".(int) $fk_entrepot;
	$sql .= " AND ps.reel > 0";

	if (!empty($search)) {
		$sql .= " AND (p.ref LIKE '%".$db->escape($search)."%'";
		$sql .= " OR p.label LIKE '%".$db->escape($search)."%')";
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return 0;
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);

	return (int) $obj->nb;
}
