<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    labels.php
 * \ingroup binloc
 * \brief   Bin label/sticker builder
 *
 * Walks the actual assignment records of a warehouse and generates one label
 * per distinct bin in use (a bin is a distinct combination of level values —
 * bins are not first-class records). Selection narrows by location (per-level
 * filters, same semantics as the warehouse tab) and/or by product (ref/label/
 * batch search). Each label shows the compact bin code (values concatenated
 * in level order), the per-level breakdown with option descriptions, and the
 * products assigned to the bin (stock quantity deliberately ignored).
 *
 * ?output=print renders a chrome-less standalone document that triggers the
 * browser print dialog — no PDF dependency.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

$langs->loadLangs(array('products', 'stocks', 'binloc@binloc'));

if (!$user->hasRight('binloc', 'read')) {
	accessforbidden();
}

$fk_entrepot = GETPOSTINT('fk_entrepot');
$search      = GETPOST('search_product', 'alphanohtml');
$output      = GETPOST('output', 'aZ09');

$levelObj = new BinlocWarehouseLevel($db);
$locObj   = new BinlocProductLocation($db);

$wh_levels = ($fk_entrepot > 0) ? $levelObj->fetchByWarehouse($fk_entrepot) : array();

// Per-level bin filters, same convention as the warehouse tab:
// list levels by option rowid (exact), text/number by partial value
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
	if ($cfg->datatype === 'list') {
		$level_filters[] = array('fk_level' => $level_id, 'fk_option' => (int) $raw);
	} else {
		$level_filters[] = array('fk_level' => $level_id, 'value' => $raw);
	}
}

/**
 * Group assignment rows into distinct bins.
 *
 * The label is stuck on the parent bin, so when the warehouse has two or more
 * levels the deepest one (e.g. "Bag") acts as the sub-bin: it is excluded from
 * the bin identity/code, and contents are grouped under one heading per
 * sub-bin value instead. Rows with no parent-level values fall back to keying
 * on their sub value alone so no assignment silently disappears.
 *
 * @param  array $locations Rows from fetchAllByWarehouse (values attached)
 * @param  array $wh_levels Level configs keyed by rowid, position order
 * @return array            Bin objects {code, items: [{ref, label, batch}],
 *                          subbins: [{title, description, items}]}, sorted by code
 */
function binloc_labels_group_bins($locations, $wh_levels)
{
	$level_ids = array_keys($wh_levels);
	$sub_level_id = (count($level_ids) >= 2) ? (int) end($level_ids) : 0;

	$bins = array();

	foreach ($locations as $loc) {
		$key_parts = array();
		$code_parts = array();
		foreach ($wh_levels as $level_id => $cfg) {
			if ((int) $level_id === $sub_level_id) {
				continue;
			}
			if (!isset($loc->values[$level_id])) {
				continue;
			}
			$entry = $loc->values[$level_id];
			if ($entry->display === null || $entry->display === '') {
				continue;
			}
			$key_parts[] = $level_id.':'.($entry->fk_option ? 'o'.$entry->fk_option : 'v'.dol_strtolower($entry->display));
			$code_parts[] = $entry->display;
		}

		$sub_entry = ($sub_level_id && isset($loc->values[$sub_level_id])
			&& $loc->values[$sub_level_id]->display !== null && $loc->values[$sub_level_id]->display !== '')
			? $loc->values[$sub_level_id] : null;

		if (empty($key_parts)) {
			if (!$sub_entry) {
				continue; // no level values — not a valid bin
			}
			// Only the sub level has a value: that value IS the bin
			$key_parts[] = $sub_level_id.':'.($sub_entry->fk_option ? 'o'.$sub_entry->fk_option : 'v'.dol_strtolower($sub_entry->display));
			$code_parts[] = $sub_entry->display;
			$sub_entry = null;
		}

		$key = implode('|', $key_parts);
		if (!isset($bins[$key])) {
			$bin = new stdClass();
			$bin->code    = implode('', $code_parts);
			$bin->items   = array();
			$bin->subbins = array();
			$bins[$key] = $bin;
		}

		$item = new stdClass();
		$item->ref   = $loc->product_ref;
		$item->label = $loc->product_label;
		$item->batch = !empty($loc->lot_batch) ? $loc->lot_batch : '';

		if ($sub_entry) {
			$sub_key = $sub_entry->fk_option ? 'o'.$sub_entry->fk_option : 'v'.dol_strtolower($sub_entry->display);
			if (!isset($bins[$key]->subbins[$sub_key])) {
				$sub = new stdClass();
				$sub->title       = $wh_levels[$sub_level_id]->label.' '.$sub_entry->display;
				$sub->description = !empty($sub_entry->description) ? $sub_entry->description : '';
				$sub->items       = array();
				$bins[$key]->subbins[$sub_key] = $sub;
			}
			$bins[$key]->subbins[$sub_key]->items[] = $item;
		} else {
			$bins[$key]->items[] = $item;
		}
	}

	$bins = array_values($bins);
	foreach ($bins as $bin) {
		$bin->subbins = array_values($bin->subbins);
		usort($bin->subbins, function ($a, $b) {
			return strnatcasecmp($a->title, $b->title);
		});
	}
	usort($bins, function ($a, $b) {
		return strnatcasecmp($a->code, $b->code);
	});
	return $bins;
}

/**
 * Render one item list (shared by the loose-items and sub-bin sections)
 *
 * @param  array $items Item objects {ref, label, batch}
 * @return string       HTML <ul>
 */
function binloc_labels_render_items($items)
{
	$html = '<ul>';
	foreach ($items as $item) {
		$html .= '<li><strong>'.dol_escape_htmltag($item->ref).'</strong>';
		if ($item->label !== '' && $item->label !== null) {
			$html .= ' &mdash; '.dol_escape_htmltag($item->label);
		}
		if ($item->batch !== '') {
			$html .= ' <span class="binloc-label-batch">['.dol_escape_htmltag($item->batch).']</span>';
		}
		$html .= '</li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Render the label cards grid (shared by preview and print output).
 * No per-level breakdown — the bin code already carries it; the Key legend
 * explains the codes once per page instead of once per label.
 *
 * @param  array $bins Bin objects from binloc_labels_group_bins
 * @return string      HTML
 */
function binloc_labels_render_cards($bins)
{
	global $langs;

	$html = '<div class="binloc-label-sheet">';
	foreach ($bins as $bin) {
		$html .= '<div class="binloc-label">';
		$html .= '<div class="binloc-label-code">'.dol_escape_htmltag($bin->code).'</div>';
		$html .= '<div class="binloc-label-items">';
		$html .= '<div class="binloc-label-items-title">'.$langs->trans('BinContents').'</div>';
		if (!empty($bin->items)) {
			$html .= binloc_labels_render_items($bin->items);
		}
		foreach ($bin->subbins as $sub) {
			$html .= '<div class="binloc-label-subbin"><strong>'.dol_escape_htmltag($sub->title).'</strong>';
			if ($sub->description !== '') {
				$html .= ' <span class="binloc-label-desc">('.dol_escape_htmltag($sub->description).')</span>';
			}
			$html .= '</div>';
			$html .= binloc_labels_render_items($sub->items);
		}
		$html .= '</div>';
		$html .= '</div>';
	}
	$html .= '</div>';
	return $html;
}

$bins = array();
if ($fk_entrepot > 0 && !empty($wh_levels)) {
	$locations = $locObj->fetchAllByWarehouse($fk_entrepot, $search, 'p.ref', 'ASC', 0, 0, $level_filters);
	$bins = binloc_labels_group_bins($locations, $wh_levels);
}

// ---- PRINT OUTPUT: standalone chrome-less document ----

if ($output === 'print' && $fk_entrepot > 0) {
	$css_url = dol_buildpath('/binloc/css/binloc.css', 1);
	print '<!DOCTYPE html>'."\n";
	print '<html><head>'."\n";
	print '<meta charset="utf-8">'."\n";
	print '<title>'.dol_escape_htmltag($langs->trans('BinLabels')).'</title>'."\n";
	print '<link rel="stylesheet" href="'.$css_url.'?v=2.4.0">'."\n";
	print '<style>body { margin: 10mm; font-family: sans-serif; } .binloc-legend { font-size: 0.85em; margin-bottom: 8px; }</style>'."\n";
	print '</head><body class="binloc-print-body">'."\n";
	if (empty($bins)) {
		print '<p>'.$langs->trans('NoBinsMatch').'</p>';
	} else {
		print binloc_render_level_legend($wh_levels);
		print binloc_labels_render_cards($bins);
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
		if ($user->hasRight('binloc', 'admin') || $user->admin) {
			$setup_url = dol_buildpath('/binloc/admin/warehouse_levels.php?fk_entrepot='.$fk_entrepot, 1);
			print '<a href="'.$setup_url.'" class="button smallpaddingimp">'.$langs->trans('ConfigureLevels').'</a>';
		}
		print '</div>';
	} else {
		// Selection form: by location (per-level filters) and/or by product (search)
		print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
		print '<div class="marginbottomonly binloc-filter-bar">';
		print '<input type="text" name="search_product" class="flat minwidth200" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('SearchProduct')).'">';
		foreach ($wh_levels as $level_id => $cfg) {
			$raw = isset($level_filter_raw[$level_id]) ? $level_filter_raw[$level_id] : '';
			if ($cfg->datatype === 'list') {
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
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Refresh').'">';
		if (!empty($search) || !empty($level_filters)) {
			print ' <a href="'.$_SERVER['PHP_SELF'].'?fk_entrepot='.$fk_entrepot.'" class="button smallpaddingimp">'.$langs->trans('Reset').'</a>';
		}
		print '</div>';
		print '</form>';

		print binloc_render_level_legend($wh_levels);

		if (empty($bins)) {
			print '<div class="opacitymedium">'.$langs->trans('NoBinsMatch').'</div>';
		} else {
			$print_url = $_SERVER['PHP_SELF'].'?fk_entrepot='.$fk_entrepot;
			$print_url .= (!empty($search) ? '&search_product='.urlencode($search) : '');
			$print_url .= $filter_param;
			$print_url .= '&output=print';

			print '<div class="marginbottomonly">';
			print '<span class="opacitymedium">'.$langs->trans('LabelsCount', count($bins)).'</span> ';
			print '<a href="'.$print_url.'" target="_blank" class="button">';
			print img_picto('', 'printer', 'class="pictofixedwidth"').$langs->trans('PrintLabels');
			print '</a>';
			print '</div>';

			print binloc_labels_render_cards($bins);
		}
	}
}

print dol_get_fiche_end();
llxFooter();
$db->close();
