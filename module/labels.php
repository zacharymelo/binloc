<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    labels.php
 * \ingroup binloc
 * \brief   Bin label/sticker builder
 *
 * One label per bin at a chosen LEVEL of the warehouse hierarchy ("Print
 * labels for: Shelf"): the label's identity is the path from the top level
 * down to that level, its contents are everything assigned below, grouped by
 * the next level down. Bins come from the assignment records; optionally the
 * configured value ranges are enumerated too so not-yet-stocked bins get a
 * tag (transient — nothing is persisted; see lib/binloc_bins.lib.php).
 *
 * Label anatomy: title = full code (identical to the system's location
 * code — the error-prevention rule); corner = the label's own level value,
 * sized for reading distance; then the value's description, then contents.
 * Layout (size, padding, fonts, toggles) is per warehouse with an optional
 * override per label level.
 *
 * ?output=print renders a chrome-less standalone document that triggers the
 * browser print dialog — no PDF dependency.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/lib/binloc_bins.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

$langs->loadLangs(array('products', 'stocks', 'binloc@binloc'));

if (!$user->hasRight('binloc', 'read')) {
	accessforbidden();
}

$fk_entrepot   = GETPOSTINT('fk_entrepot');
$search        = GETPOST('search_product', 'alphanohtml');
$output        = GETPOST('output', 'aZ09');
$action        = GETPOST('action', 'aZ09');
$label_level   = GETPOSTINT('label_level');
$include_empty = GETPOSTINT('include_empty');

$can_admin = ($user->admin || $user->hasRight('binloc', 'admin'));

$levelObj = new BinlocWarehouseLevel($db);
$locObj   = new BinlocProductLocation($db);

$wh_levels = ($fk_entrepot > 0) ? $levelObj->fetchByWarehouse($fk_entrepot) : array();

// Which level the labels are stuck on: explicit choice, else the warehouse
// default (honouring a pre-2.9 stored sub-bin setting)
if ($label_level <= 0 || !isset($wh_levels[$label_level])) {
	$wh_layout = ($fk_entrepot > 0) ? binloc_get_label_layout($db, $fk_entrepot) : binloc_label_layout_defaults();
	$label_level = binloc_default_label_level($wh_levels, isset($wh_layout->legacy_sub_level) ? (int) $wh_layout->legacy_sub_level : 0);
}

// ---- Layout settings actions (admin) ----
if ($action === 'savelayout' && $fk_entrepot > 0) {
	if (!$can_admin) {
		accessforbidden();
	}
	$layout = binloc_label_layout_defaults();
	$numeric_fields = array('width_mm', 'height_mm', 'pad_top_mm', 'pad_right_mm', 'pad_bottom_mm', 'pad_left_mm', 'font_pt', 'corner_pt', 'border_mm', 'sheet_margin_mm');
	foreach ($numeric_fields as $key) {
		$posted = GETPOST('layout_'.$key, 'alphanohtml');
		if ($posted !== '') {
			$layout->$key = (float) price2num($posted);
		}
	}
	$layout->code_sep           = GETPOST('layout_code_sep', 'alphanohtml');
	$layout->show_description   = GETPOST('layout_show_description', 'aZ09') ? 1 : 0;
	$layout->show_contents      = GETPOST('layout_show_contents', 'aZ09') ? 1 : 0;
	$layout->show_batch         = GETPOST('layout_show_batch', 'aZ09') ? 1 : 0;
	$layout->show_product_label = GETPOST('layout_show_product_label', 'aZ09') ? 1 : 0;
	$scope = GETPOST('layout_scope', 'aZ09');
	if ($scope === 'reset') {
		// Drop this level's override so it falls back to the warehouse default
		binloc_delete_label_layout($db, $fk_entrepot, $label_level);
		setEventMessages($langs->trans('LabelLayoutResetDone'), null, 'mesgs');
	} elseif (binloc_save_label_layout($db, $fk_entrepot, $layout, ($scope === 'warehouse' ? 0 : $label_level)) > 0) {
		setEventMessages($langs->trans('LabelLayoutSaved'), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	$action = '';
}

$layout = ($fk_entrepot > 0) ? binloc_get_label_layout($db, $fk_entrepot, $label_level) : binloc_label_layout_defaults();

// Per-level bin filters, same convention as the warehouse tab:
// option-backed levels by option rowid (exact), text/number by partial value
$level_filters = array();
$level_filter_raw = array();
$filter_param = '';
foreach ($wh_levels as $level_id => $cfg) {
	$raw = GETPOST('search_level'.$level_id, 'alphanohtml');
	if ($raw === '' || $raw === null) {
		continue;
	}
	$level_filter_raw[$level_id] = $raw;
	$filter_param .= '&search_level'.$level_id.'='.urlencode($raw);
	if (binloc_datatype_has_options($cfg->datatype)) {
		$level_filters[] = array('fk_level' => $level_id, 'fk_option' => (int) $raw);
	} else {
		$level_filters[] = array('fk_level' => $level_id, 'value' => $raw);
	}
}

/**
 * Render one item list (shared by the loose-items and sub-bin sections)
 *
 * @param  array    $items  Item objects {ref, label, batch, subpath}
 * @param  stdClass $layout Label layout (show_batch, show_product_label)
 * @return string           HTML <ul>
 */
function binloc_labels_render_items($items, $layout)
{
	$html = '<ul>';
	foreach ($items as $item) {
		$html .= '<li>';
		if ($item->subpath !== '') {
			$html .= '<span class="binloc-label-subpath">'.dol_escape_htmltag($item->subpath).'</span> ';
		}
		$html .= '<strong>'.dol_escape_htmltag($item->ref).'</strong>';
		if (!empty($layout->show_product_label) && $item->label !== '' && $item->label !== null) {
			$html .= ' &mdash; '.dol_escape_htmltag($item->label);
		}
		if (!empty($layout->show_batch) && $item->batch !== '') {
			$html .= ' <span class="binloc-label-batch">['.dol_escape_htmltag($item->batch).']</span>';
		}
		$html .= '</li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Render the label cards (shared by preview and print output)
 *
 * @param  array    $bins   Bins from binloc_bins_from_rows
 * @param  stdClass $layout Label layout
 * @return string           HTML
 */
function binloc_labels_render_cards($bins, $layout)
{
	$html = '<div class="binloc-label-sheet">';
	foreach ($bins as $bin) {
		// Identity-only when the layout hides contents (a rack-end label names
		// the rack; its shelves carry their own labels) or the bin is empty
		$identity_only = empty($layout->show_contents) || $bin->is_empty;
		$html .= '<div class="binloc-label'.($identity_only ? ' binloc-label-empty' : '').'">';
		$html .= '<div class="binloc-label-head">';
		$html .= '<div class="binloc-label-code">'.dol_escape_htmltag($bin->code).'</div>';
		if ($layout->corner_pt > 0 && $bin->own_value !== '') {
			$html .= '<div class="binloc-label-corner">'.dol_escape_htmltag($bin->own_value).'</div>';
		}
		$html .= '</div>';
		if (!empty($layout->show_description) && $bin->own_description !== '') {
			$html .= '<div class="binloc-label-descline">'.dol_escape_htmltag($bin->own_description).'</div>';
		}
		if (!$identity_only) {
			$html .= '<div class="binloc-label-items">';
			if (!empty($bin->items)) {
				$html .= binloc_labels_render_items($bin->items, $layout);
			}
			foreach ($bin->subbins as $sub) {
				$html .= '<div class="binloc-label-subbin"><strong>'.dol_escape_htmltag($sub->title).'</strong>';
				if ($sub->description !== '') {
					$html .= ' <span class="binloc-label-desc">('.dol_escape_htmltag($sub->description).')</span>';
				}
				$html .= '</div>';
				$html .= binloc_labels_render_items($sub->items, $layout);
			}
			$html .= '</div>';
		}
		$html .= '</div>';
	}
	$html .= '</div>';
	return $html;
}

// ---- Build the bins ----

$bins = array();
$notices = array();
if ($fk_entrepot > 0 && !empty($wh_levels) && $label_level > 0) {
	$rows = $locObj->fetchAllByWarehouse($fk_entrepot, $search, 'p.ref', 'ASC', 0, 0, $level_filters);

	// Product search means "bins holding these products" — enumerating empty
	// bins alongside would contradict it, so only enumerate without a search
	if ($include_empty && $search === '') {
		$enum = binloc_enumerate_bins($wh_levels, $label_level, $level_filter_raw);
		if (!empty($enum['unenumerable'])) {
			$notices[] = $langs->trans('LabelUnenumerable', implode(', ', $enum['unenumerable']));
		}
		if ($enum['truncated']) {
			$notices[] = $langs->trans('LabelEnumTruncated', count($enum['rows']));
		}
		$rows = array_merge($rows, $enum['rows']);
	}

	$bins = binloc_bins_from_rows($rows, $wh_levels, $label_level, (string) $layout->code_sep);
}

// ---- PRINT OUTPUT: standalone chrome-less document ----

if ($output === 'print' && $fk_entrepot > 0) {
	$css_url = dol_buildpath('/binloc/css/binloc.css', 1);
	print '<!DOCTYPE html>'."\n";
	print '<html><head>'."\n";
	print '<meta charset="utf-8">'."\n";
	print '<title>'.dol_escape_htmltag($langs->trans('BinLabels')).'</title>'."\n";
	print '<link rel="stylesheet" href="'.$css_url.'?v=2.10.0">'."\n";
	print '<style>body { margin: '.binloc_css_num($layout->sheet_margin_mm).'mm; font-family: sans-serif; } .binloc-legend { font-size: 0.85em; margin-bottom: 2mm; }</style>'."\n";
	print binloc_label_layout_css($layout);
	print '</head><body class="binloc-print-body">'."\n";
	if (empty($bins)) {
		print '<p>'.$langs->trans('NoBinsMatch').'</p>';
	} else {
		print binloc_render_level_legend($wh_levels);
		print binloc_labels_render_cards($bins, $layout);
	}
	print '<script>window.addEventListener("load", function () { window.print(); });</script>'."\n";
	print '</body></html>';
	exit;
}

// ---- NORMAL VIEW ----

llxHeader('', $langs->trans('BinLabels'), binloc_help_url());

binloc_print_assets();

print dol_get_fiche_head(array(), '', $langs->trans('BinLabels'), -1, 'stock');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('BinLabelsDesc').'</div>';

// Warehouse selector
print '<div class="marginbottomonly">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
print '<strong>'.$langs->trans('Warehouse').'</strong>: ';
print binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot, 'flat minwidth250', 'onchange="this.form.submit()"');
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Select').'">';
print '</form>';
print '</div>';

if ($fk_entrepot > 0) {
	if (empty($wh_levels)) {
		print '<div class="info">';
		print $langs->trans('NoLevelsConfigured').' ';
		if ($can_admin) {
			$setup_url = dol_buildpath('/binloc/admin/warehouse_levels.php?fk_entrepot='.$fk_entrepot, 1);
			print '<a href="'.$setup_url.'" class="button smallpaddingimp">'.$langs->trans('ConfigureLevels').'</a>';
		}
		print '</div>';
	} else {
		$level_label = $wh_levels[$label_level]->label;

		// Selection form: label level, by location (per-level filters), by product (search), empty bins
		print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
		print '<div class="marginbottomonly binloc-filter-bar">';
		print '<label><strong>'.$langs->trans('LabelPrintFor').'</strong> ';
		print '<select name="label_level" class="flat" onchange="this.form.submit()">';
		foreach ($wh_levels as $level_id => $cfg) {
			print '<option value="'.$level_id.'"'.((int) $level_id === $label_level ? ' selected' : '').'>'.dol_escape_htmltag($cfg->label).'</option>';
		}
		print '</select></label>';
		print '</div>';
		print '<div class="marginbottomonly binloc-filter-bar">';
		print '<input type="text" name="search_product" class="flat minwidth200" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('SearchProduct')).'">';
		foreach ($wh_levels as $level_id => $cfg) {
			$raw = isset($level_filter_raw[$level_id]) ? $level_filter_raw[$level_id] : '';
			if (binloc_datatype_has_options($cfg->datatype)) {
				print ' <select name="search_level'.$level_id.'" class="flat" aria-label="'.dol_escape_htmltag($cfg->label).'">';
				print '<option value="">'.dol_escape_htmltag($cfg->label).'…</option>';
				foreach ($cfg->options as $opt) {
					$sel = ((string) $opt->id === (string) $raw) ? ' selected' : '';
					$suffix = (!$opt->active ? ' ('.$langs->trans('LegacyValue').')' : '');
					$title = (!empty($opt->description) ? ' title="'.dol_escape_htmltag($opt->description).'"' : '');
					print '<option value="'.(int) $opt->id.'"'.$sel.$title.'>'.dol_escape_htmltag($opt->value.$suffix).'</option>';
				}
				print '</select>';
			} else {
				print ' <input type="text" name="search_level'.$level_id.'" class="flat width75" value="'.dol_escape_htmltag($raw).'" placeholder="'.dol_escape_htmltag($cfg->label).'">';
			}
		}
		print ' <label class="nowraponall" title="'.dol_escape_htmltag($langs->trans('LabelIncludeEmptyHint')).'"><input type="checkbox" name="include_empty" value="1"'.($include_empty ? ' checked' : '').'> '.$langs->trans('LabelIncludeEmpty').'</label>';
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Refresh').'">';
		if (!empty($search) || !empty($level_filters) || $include_empty) {
			print ' <a href="'.$_SERVER['PHP_SELF'].'?fk_entrepot='.$fk_entrepot.'&label_level='.$label_level.'" class="button smallpaddingimp">'.$langs->trans('Reset').'</a>';
		}
		print '</div>';
		print '</form>';

		// ---- Layout settings (admin): this level's override, or the warehouse default ----
		if ($can_admin) {
			$layout_fields = array(
				'width_mm'      => 'LabelWidthMm',
				'height_mm'     => 'LabelHeightMm',
				'pad_top_mm'    => 'LabelPadTopMm',
				'pad_right_mm'  => 'LabelPadRightMm',
				'pad_bottom_mm' => 'LabelPadBottomMm',
				'pad_left_mm'   => 'LabelPadLeftMm',
				'font_pt'       => 'LabelFontPt',
				'corner_pt'     => 'LabelCornerPt',
			);
			print '<details class="binloc-card binloc-label-settings">';
			print '<summary><strong>'.$langs->trans('LabelLayoutFor', dol_escape_htmltag($level_label)).'</strong>';
			print ' <span class="opacitymedium small">'.($layout->is_level_specific ? $langs->trans('LabelLayoutIsOverride') : $langs->trans('LabelLayoutIsDefault')).'</span>';
			print '</summary>';
			print '<div class="opacitymedium small marginbottomonly">'.$langs->trans('LabelLayoutDesc').'</div>';
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="savelayout">';
			print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
			print '<input type="hidden" name="label_level" value="'.$label_level.'">';
			if (!empty($search)) {
				print '<input type="hidden" name="search_product" value="'.dol_escape_htmltag($search).'">';
			}
			foreach ($level_filter_raw as $level_id => $raw) {
				print '<input type="hidden" name="search_level'.$level_id.'" value="'.dol_escape_htmltag($raw).'">';
			}
			if ($include_empty) {
				print '<input type="hidden" name="include_empty" value="1">';
			}
			print '<div class="binloc-inline-form">';
			foreach ($layout_fields as $key => $transkey) {
				print '<label class="binloc-layout-field">'.$langs->trans($transkey).'<br>';
				print '<input type="text" name="layout_'.$key.'" class="flat width50 right" value="'.binloc_css_num($layout->$key).'">';
				print '</label>';
			}
			print '</div>';
			print '<div class="binloc-inline-form margintoponly">';
			print '<label class="binloc-layout-field">'.$langs->trans('LabelBorderMm').'<br>';
			print '<input type="text" name="layout_border_mm" class="flat width50 right" value="'.binloc_css_num($layout->border_mm).'">';
			print '</label>';
			print '<label class="binloc-layout-field">'.$langs->trans('LabelSheetMarginMm').'<br>';
			print '<input type="text" name="layout_sheet_margin_mm" class="flat width50 right" value="'.binloc_css_num($layout->sheet_margin_mm).'">';
			print '</label>';
			print '<label class="binloc-layout-field">'.$langs->trans('LabelCodeSep').'<br>';
			print '<input type="text" name="layout_code_sep" class="flat width50" value="'.dol_escape_htmltag($layout->code_sep).'" maxlength="3">';
			print '</label>';
			print '<label class="binloc-layout-field"><input type="checkbox" name="layout_show_description" value="1"'.($layout->show_description ? ' checked' : '').'> '.$langs->trans('LabelShowDescription').'</label>';
			print '<label class="binloc-layout-field" title="'.dol_escape_htmltag($langs->trans('LabelShowContentsHint')).'"><input type="checkbox" name="layout_show_contents" value="1"'.($layout->show_contents ? ' checked' : '').'> '.$langs->trans('LabelShowContents').'</label>';
			print '<label class="binloc-layout-field"><input type="checkbox" name="layout_show_batch" value="1"'.($layout->show_batch ? ' checked' : '').'> '.$langs->trans('LabelShowBatch').'</label>';
			print '<label class="binloc-layout-field"><input type="checkbox" name="layout_show_product_label" value="1"'.($layout->show_product_label ? ' checked' : '').'> '.$langs->trans('LabelShowProductLabel').'</label>';
			print '</div>';
			print '<div class="binloc-inline-form margintoponly">';
			print '<button type="submit" name="layout_scope" value="level" class="button smallpaddingimp">'.dol_escape_htmltag($langs->trans('LabelLayoutSaveForLevel', $level_label)).'</button>';
			print '<button type="submit" name="layout_scope" value="warehouse" class="button smallpaddingimp">'.dol_escape_htmltag($langs->trans('LabelLayoutSaveAsDefault')).'</button>';
			if ($layout->is_level_specific) {
				print '<button type="submit" name="layout_scope" value="reset" class="button smallpaddingimp">'.dol_escape_htmltag($langs->trans('LabelLayoutReset')).'</button>';
			}
			print '</div>';
			print '</form>';
			print '</details>';
		}

		print binloc_label_layout_css($layout);
		print binloc_render_level_legend($wh_levels);

		foreach ($notices as $notice) {
			print '<div class="warning">'.dol_escape_htmltag($notice).'</div>';
		}

		if (empty($bins)) {
			print '<div class="opacitymedium">'.$langs->trans('NoBinsMatch').'</div>';
		} else {
			$print_url = $_SERVER['PHP_SELF'].'?fk_entrepot='.$fk_entrepot.'&label_level='.$label_level;
			$print_url .= (!empty($search) ? '&search_product='.urlencode($search) : '');
			$print_url .= $filter_param;
			$print_url .= ($include_empty ? '&include_empty=1' : '');
			$print_url .= '&output=print';

			print '<div class="marginbottomonly">';
			print '<span class="opacitymedium">'.$langs->trans('LabelsCountFor', count($bins), dol_escape_htmltag($level_label)).'</span> ';
			print '<a href="'.$print_url.'" target="_blank" class="button">';
			print img_picto('', 'printer', 'class="pictofixedwidth"').$langs->trans('PrintLabels');
			print '</a>';
			print '</div>';

			print binloc_labels_render_cards($bins, $layout);
		}
	}
}

print dol_get_fiche_end();
llxFooter();
$db->close();
