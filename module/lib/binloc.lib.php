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

	$head[$h][0] = dol_buildpath('/binloc/admin/import_export.php', 1);
	$head[$h][1] = $langs->trans('CsvImportExport');
	$head[$h][2] = 'importexport';
	$h++;

	return $head;
}

/**
 * URL of the in-app user guide, for llxHeader's help icon.
 * Must be fully qualified: Dolibarr's getHelpParamFor() only treats
 * help_url as a direct link when it starts with "http".
 *
 * @return string
 */
function binloc_help_url()
{
	return dol_buildpath('/binloc/help.php', 2);
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

	$v = '2.12.0';
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
 * Whether a level's values are backed by option rows (rowid-referenced,
 * renameable, disableable) rather than a raw text/number string. Both
 * 'list' (hand-typed values) and 'letter' (A..Z / AA..ZZ, generated) use the
 * same llx_binloc_level_options storage and the same select-based input.
 *
 * @param  string $datatype Level datatype
 * @return bool
 */
function binloc_datatype_has_options($datatype)
{
	return in_array($datatype, array('list', 'letter'), true);
}

/**
 * Convert a 1-based index to a spreadsheet-style column code: 1=A, 26=Z,
 * 27=AA, 28=AB, ... 702=ZZ (bijective base-26)
 *
 * @param  int $n 1-based index
 * @return string
 */
function binloc_letter_code($n)
{
	$code = '';
	while ($n > 0) {
		$n--;
		$code = chr(65 + ($n % 26)).$code;
		$n = intdiv($n, 26);
	}
	return $code;
}

/**
 * Sequence of letter codes from A up to and including $end, in spreadsheet
 * order (A, B, ... Z, AA, AB, ...). $end must be 1-2 letters (A-Z or AA-ZZ).
 *
 * @param  string $end End code, e.g. "Z" or "AZ" (case-insensitive)
 * @return string[]    Empty array if $end doesn't match the expected pattern
 */
function binloc_letter_sequence($end)
{
	$end = strtoupper(trim((string) $end));
	if (!preg_match('/^[A-Z]{1,2}$/', $end)) {
		return array();
	}

	$end_n = (dol_strlen($end) === 1)
		? (ord($end[0]) - 64)
		: ((ord($end[0]) - 64) * 26 + (ord($end[1]) - 64));

	$out = array();
	for ($i = 1; $i <= $end_n; $i++) {
		$out[] = binloc_letter_code($i);
	}
	return $out;
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

	if (binloc_datatype_has_options($datatype)) {
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
			$title = (!empty($opt->description) ? ' title="'.dol_escape_htmltag($opt->description).'"' : '');
			$html .= '<option value="'.(int) $opt->id.'"'.$sel.$title.'>'.dol_escape_htmltag($opt->value.$suffix).'</option>';
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
 * Render a "key" legend for a warehouse's dropdown values: every option code
 * that has a description is listed as "code = description", grouped by level.
 * Returns '' when no option has a description, so pages can print it blindly.
 *
 * @param  array $level_cfgs Level configs keyed by rowid (fetchByWarehouse output)
 * @return string            HTML fragment or ''
 */
function binloc_render_level_legend($level_cfgs)
{
	global $langs;

	$groups = array();
	foreach ($level_cfgs as $cfg) {
		if (!binloc_datatype_has_options($cfg->datatype)) {
			continue;
		}
		$chips = '';
		foreach ($cfg->options as $opt) {
			if (!$opt->active || empty($opt->description)) {
				continue;
			}
			$chips .= '<span class="binloc-chip"><b>'.dol_escape_htmltag($opt->value).'</b>'.dol_escape_htmltag($opt->description).'</span>';
		}
		if ($chips !== '') {
			$groups[] = '<span class="binloc-legend-group"><span class="binloc-legend-level">'.dol_escape_htmltag($cfg->label).'</span>'.$chips.'</span>';
		}
	}

	if (empty($groups)) {
		return '';
	}

	// Chips (code + meaning) grouped per level, instead of one run-on sentence
	$html = '<div class="binloc-legend">';
	$html .= '<span class="binloc-legend-title">'.$langs->trans('BinValueLegend').'</span>';
	$html .= implode('', $groups);
	$html .= '</div>';
	return $html;
}

/**
 * Compact bin code: the level values concatenated in position order with no
 * separator (e.g. values A, L, 2, 5, 3 -> "AL253"). Used on bin labels.
 *
 * @param  array $level_cfgs Level configs keyed by rowid (fetchByWarehouse output)
 * @param  array $values     Values keyed by level rowid ({fk_option, value, display})
 * @return string
 */
function binloc_compact_code($level_cfgs, $values)
{
	$parts = array();
	foreach ($level_cfgs as $id => $cfg) {
		if (isset($values[$id]) && $values[$id]->display !== null && $values[$id]->display !== '') {
			$parts[] = $values[$id]->display;
		}
	}
	return implode('', $parts);
}

/**
 * Default label layout (all dimensions in mm, fonts in pt).
 * pad_top_mm doubles as the blank header strip at the top of each label
 * (e.g. for slide-in bin holders) — no hardcoded rectangle anywhere.
 *
 * Layouts are stored per warehouse, optionally overridden per label level
 * (a bag tag and a shelf tag are different stock AND different reading
 * distances — see binloc_get_label_layout()).
 *
 * @return stdClass
 */
function binloc_label_layout_defaults()
{
	$layout = new stdClass();
	$layout->width_mm        = 75.0;
	$layout->height_mm       = 0.0; // 0 = automatic height
	$layout->pad_top_mm      = 21.0;
	$layout->pad_right_mm    = 3.0;
	$layout->pad_bottom_mm   = 3.0;
	$layout->pad_left_mm     = 3.0;
	$layout->font_pt         = 9.0;
	$layout->corner_pt       = 18.0; // the label's own level value, top-right (0 = hide)
	$layout->border_mm       = 0.3; // 0 = no border (pre-cut sticker stock)
	$layout->sheet_margin_mm = 0.0; // print-sheet margin around the whole grid
	$layout->code_sep        = ''; // '' = values joined (AL253B3); e.g. '-' for A-L-2...
	$layout->print_mode      = 'sheet'; // 'sheet' = grid, cut apart | 'roll' = label printer, one label per page
	$layout->show_description = 1; // own level value's description under the title
	$layout->show_contents   = 1; // 0 = identity only (title, corner, description) — e.g. rack-end labels
	$layout->show_batch      = 1; // lot/serial batch on label items
	$layout->show_product_label = 1; // product name next to the ref
	return $layout;
}

/**
 * Clamp a label layout to sane printable ranges (in place)
 *
 * @param  stdClass $layout Layout object
 * @return stdClass         The same object
 */
function binloc_label_layout_clamp($layout)
{
	$layout->width_mm        = max(20.0, min(300.0, (float) $layout->width_mm));
	$layout->height_mm       = max(0.0, min(300.0, (float) $layout->height_mm));
	$layout->pad_top_mm      = max(0.0, min(100.0, (float) $layout->pad_top_mm));
	$layout->pad_right_mm    = max(0.0, min(100.0, (float) $layout->pad_right_mm));
	$layout->pad_bottom_mm   = max(0.0, min(100.0, (float) $layout->pad_bottom_mm));
	$layout->pad_left_mm     = max(0.0, min(100.0, (float) $layout->pad_left_mm));
	$layout->font_pt         = max(4.0, min(30.0, (float) $layout->font_pt));
	$layout->corner_pt       = max(0.0, min(72.0, (float) $layout->corner_pt));
	$layout->border_mm       = max(0.0, min(2.0, (float) $layout->border_mm));
	$layout->sheet_margin_mm = max(0.0, min(50.0, (float) $layout->sheet_margin_mm));
	$layout->code_sep        = dol_substr((string) $layout->code_sep, 0, 3);
	$layout->print_mode      = in_array($layout->print_mode, array('sheet', 'roll'), true) ? $layout->print_mode : 'sheet';
	$layout->show_description = empty($layout->show_description) ? 0 : 1;
	$layout->show_contents   = empty($layout->show_contents) ? 0 : 1;
	$layout->show_batch      = empty($layout->show_batch) ? 0 : 1;
	$layout->show_product_label = empty($layout->show_product_label) ? 0 : 1;
	return $layout;
}

/**
 * Constant name holding a stored layout: one per warehouse, plus an
 * optional override per label level
 *
 * @param  int $fk_entrepot Warehouse ID
 * @param  int $fk_level    Level rowid (0 = the warehouse default)
 * @return string
 */
function binloc_label_layout_const_name($fk_entrepot, $fk_level = 0)
{
	return 'BINLOC_LABEL_LAYOUT_'.((int) $fk_entrepot).($fk_level > 0 ? '_L'.((int) $fk_level) : '');
}

/**
 * Overlay a stored JSON layout onto $layout (type-aware, unknown keys ignored)
 *
 * @param  stdClass    $layout Layout to modify in place
 * @param  string|null $raw    Stored JSON ('' or null = nothing stored)
 * @return bool                True when something was overlaid
 */
function binloc_label_layout_overlay($layout, $raw)
{
	$stored = $raw ? json_decode($raw) : null;
	if (!is_object($stored)) {
		return false;
	}
	foreach (get_object_vars(binloc_label_layout_defaults()) as $key => $default) {
		if (!isset($stored->$key)) {
			continue;
		}
		if (is_string($default)) {
			$layout->$key = (string) $stored->$key;
		} elseif (is_numeric($stored->$key)) {
			$layout->$key = is_int($default) ? (int) $stored->$key : (float) $stored->$key;
		}
	}
	// Pre-2.9 layouts stored a "sub_level" (which level groups contents);
	// kept only so the labels page can derive its default label level from it
	if (isset($stored->sub_level) && is_numeric($stored->sub_level)) {
		$layout->legacy_sub_level = (int) $stored->sub_level;
	}
	return true;
}

/**
 * Load the label layout for a warehouse and, optionally, one label level.
 * Resolution: defaults <- warehouse layout <- per-level layout (if any).
 * $layout->is_level_specific tells the settings panel which one it is editing.
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_entrepot Warehouse ID
 * @param  int    $fk_level    Label level rowid (0 = warehouse default only)
 * @return stdClass            Layout object (see binloc_label_layout_defaults)
 */
function binloc_get_label_layout($db, $fk_entrepot, $fk_level = 0)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php'; // dolibarr_get_const

	$layout = binloc_label_layout_defaults();
	$layout->legacy_sub_level = 0;
	$layout->is_level_specific = false;

	binloc_label_layout_overlay($layout, dolibarr_get_const($db, binloc_label_layout_const_name($fk_entrepot), $conf->entity));
	if ($fk_level > 0) {
		$layout->is_level_specific = binloc_label_layout_overlay($layout, dolibarr_get_const($db, binloc_label_layout_const_name($fk_entrepot, $fk_level), $conf->entity));
	}

	return binloc_label_layout_clamp($layout);
}

/**
 * Persist a label layout (warehouse default, or one level's override)
 *
 * @param  DoliDB   $db          Database handler
 * @param  int      $fk_entrepot Warehouse ID
 * @param  stdClass $layout      Layout object
 * @param  int      $fk_level    Level rowid (0 = warehouse default)
 * @return int                   >0 if OK, <0 if KO
 */
function binloc_save_label_layout($db, $fk_entrepot, $layout, $fk_level = 0)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php'; // dolibarr_set_const

	binloc_label_layout_clamp($layout);
	// Only the declared fields are stored — never the resolution flags
	$store = new stdClass();
	foreach (array_keys(get_object_vars(binloc_label_layout_defaults())) as $key) {
		$store->$key = $layout->$key;
	}
	return dolibarr_set_const($db, binloc_label_layout_const_name($fk_entrepot, $fk_level), json_encode($store), 'chaine', 0, '', $conf->entity);
}

/**
 * Remove one level's layout override so it falls back to the warehouse default
 *
 * @param  DoliDB $db          Database handler
 * @param  int    $fk_entrepot Warehouse ID
 * @param  int    $fk_level    Level rowid
 * @return int                 >0 if OK, <0 if KO
 */
function binloc_delete_label_layout($db, $fk_entrepot, $fk_level)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php'; // dolibarr_del_const

	if ($fk_level <= 0) {
		return 0;
	}
	return dolibarr_del_const($db, binloc_label_layout_const_name($fk_entrepot, $fk_level), $conf->entity);
}

/**
 * Format a layout number for CSS output (no locale separators, no trailing zeros)
 *
 * @param  float $value Number
 * @return string
 */
function binloc_css_num($value)
{
	$str = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
	return ($str === '' || $str === '-') ? '0' : $str;
}

/**
 * Geometry CSS for the label sheet, generated from a warehouse's layout.
 * Owns ALL sizing (width, height, padding, font); binloc.css keeps only the
 * decorative rules. Labels butt against each other with zero gap so a sheet
 * can be cut with a single slice between labels.
 *
 * @param  stdClass $layout Layout object
 * @return string           <style> block
 */
function binloc_label_layout_css($layout)
{
	$css = '.binloc-label-sheet { display: flex; flex-wrap: wrap; gap: 0; align-items: stretch; }'."\n";
	$css .= '.binloc-label { box-sizing: border-box; margin: 0; border-radius: 0;';
	$css .= ' width: '.binloc_css_num($layout->width_mm).'mm;';
	if ($layout->height_mm > 0) {
		$css .= ' height: '.binloc_css_num($layout->height_mm).'mm; overflow: hidden;';
	}
	$css .= ' padding: '.binloc_css_num($layout->pad_top_mm).'mm '.binloc_css_num($layout->pad_right_mm).'mm';
	$css .= ' '.binloc_css_num($layout->pad_bottom_mm).'mm '.binloc_css_num($layout->pad_left_mm).'mm;';
	$css .= ' font-size: '.binloc_css_num($layout->font_pt).'pt;';
	$css .= ($layout->border_mm > 0 ? ' border-width: '.binloc_css_num($layout->border_mm).'mm;' : ' border: none;');
	$css .= ' }'."\n";
	// Corner tag sized in absolute pt: it is read from further away than the
	// body text (signage rule of thumb: character height = distance / 200)
	$css .= '.binloc-label-corner { font-size: '.binloc_css_num($layout->corner_pt).'pt; }';
	return '<style>'."\n".$css."\n".'</style>'."\n";
}

/**
 * Page-level CSS for the standalone print document, by output mode.
 *
 * sheet: the grid flows on the paper the user picks; sheet_margin_mm is the
 *        margin around it.
 * roll:  a label printer (Dymo LabelWriter and the like) advances one
 *        die-cut label per page, so every label becomes its own page of
 *        exactly the label's size with no margin. The @page size lets the
 *        browser pick the matching label stock; with no height set the
 *        driver's paper size governs and the label flows from the top.
 *
 * @param  stdClass $layout Layout object
 * @return string           <style> block
 */
function binloc_label_print_css($layout)
{
	$css = 'html, body { padding: 0; font-family: sans-serif; }'."\n";
	if ($layout->print_mode === 'roll') {
		$size = '';
		if ($layout->height_mm > 0) {
			$size = ' size: '.binloc_css_num($layout->width_mm).'mm '.binloc_css_num($layout->height_mm).'mm;';
		}
		$css .= '@page { margin: 0;'.$size.' }'."\n";
		$css .= 'body { margin: 0; }'."\n";
		$css .= '.binloc-label-sheet { display: block; margin: 0; }'."\n";
		$css .= '.binloc-label { break-after: page; page-break-after: always; }'."\n";
		$css .= '.binloc-label:last-child { break-after: auto; page-break-after: auto; }'."\n";
	} else {
		$css .= 'body { margin: '.binloc_css_num($layout->sheet_margin_mm).'mm; }'."\n";
		$css .= '.binloc-label-sheet { margin: 0; }'."\n";
	}
	return '<style>'."\n".$css.'</style>'."\n";
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
 * Get all products present in a specific warehouse (for bulk assign): products
 * with stock there, plus products already holding a non-lot bin assignment
 * there even without stock (pre-assigned via the quick-assign picker).
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
	$sql .= " IFNULL(ps.reel, 0) as stock,";
	$sql .= " pl.rowid as loc_rowid,";
	$sql .= " pl.note,";
	$sql .= " (SELECT GROUP_CONCAT(CONCAT(w.label, ': ', COALESCE(o.value, v.value))";
	$sql .= "   ORDER BY w.position ASC, w.rowid ASC SEPARATOR ' / ')";
	$sql .= "   FROM ".MAIN_DB_PREFIX."binloc_location_value as v";
	$sql .= "   INNER JOIN ".MAIN_DB_PREFIX."binloc_warehouse_levels as w ON w.rowid = v.fk_level";
	$sql .= "   LEFT JOIN ".MAIN_DB_PREFIX."binloc_level_options as o ON o.rowid = v.fk_option";
	$sql .= "   WHERE v.fk_location = pl.rowid) as location";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps";
	$sql .= "   ON (ps.fk_product = p.rowid AND ps.fk_entrepot = ".(int) $fk_entrepot.")";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_product_location as pl";
	$sql .= "   ON (pl.fk_product = p.rowid AND pl.fk_entrepot = ".(int) $fk_entrepot;
	$sql .= "   AND pl.fk_product_lot = 0";
	$sql .= "   AND pl.entity IN (".getEntity('stock')."))";
	$sql .= " WHERE (ps.reel > 0 OR pl.rowid IS NOT NULL)";
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
 * Count products present in a specific warehouse (stock or bin assignment) —
 * must stay in sync with binloc_get_products_in_warehouse()
 *
 * @param  DoliDB $db           Database handler
 * @param  int    $fk_entrepot  Warehouse ID
 * @param  string $search       Optional search filter
 * @return int                  Count
 */
function binloc_count_products_in_warehouse($db, $fk_entrepot, $search = '')
{
	$sql = "SELECT COUNT(*) as nb";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps";
	$sql .= "   ON (ps.fk_product = p.rowid AND ps.fk_entrepot = ".(int) $fk_entrepot.")";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_product_location as pl";
	$sql .= "   ON (pl.fk_product = p.rowid AND pl.fk_entrepot = ".(int) $fk_entrepot;
	$sql .= "   AND pl.fk_product_lot = 0";
	$sql .= "   AND pl.entity IN (".getEntity('stock')."))";
	$sql .= " WHERE (ps.reel > 0 OR pl.rowid IS NOT NULL)";
	$sql .= " AND p.entity IN (".getEntity('product').")";

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
