<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    tab_warehouse_locations.php
 * \ingroup binloc
 * \brief   Warehouse card tab — shows all product bin locations in this warehouse
 *
 * Row edits and deletes go through the Binloc AJAX endpoints; the page keeps
 * server-side search/sort/pagination.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/stock.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

$langs->loadLangs(array('products', 'stocks', 'binloc@binloc'));

$id     = GETPOSTINT('id');
$search = GETPOST('search_product', 'alphanohtml');

// Pagination
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'p.ref';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page      = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
$limit     = GETPOSTINT('limit') ?: $conf->liste_limit;
$offset    = $limit * max(0, $page);

$object = new Entrepot($db);
if ($id > 0) {
	$object->fetch($id);
}

if (empty($object->id) || $object->id <= 0) {
	accessforbidden('Warehouse not found');
}

$can_write = $user->hasRight('binloc', 'write');

$levelObj = new BinlocWarehouseLevel($db);
$locObj   = new BinlocProductLocation($db);

$wh_levels = $levelObj->fetchByWarehouse($id);

// Per-level bin filters (explore by bin): search_level{rowid} params.
// List levels filter by option rowid (exact); text/number by partial value.
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
$has_filters = !empty($level_filters);

// ---- VIEW ----

llxHeader('', $langs->trans('BinLocations').' - '.$object->ref, binloc_help_url());

binloc_print_assets();

$head = stock_prepare_head($object);
print dol_get_fiche_head($head, 'binloc', $langs->trans('Warehouse'), -1, 'stock');

$linkback = '<a href="'.DOL_URL_ROOT.'/product/stock/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

if (empty($wh_levels)) {
	print '<div class="info marginbottomonly">';
	print $langs->trans('NoLevelsConfigured').' ';
	if ($user->hasRight('binloc', 'admin') || $user->admin) {
		$setup_url = dol_buildpath('/binloc/admin/warehouse_levels.php?fk_entrepot='.$id, 1);
		print '<a href="'.$setup_url.'" class="button smallpaddingimp">'.$langs->trans('ConfigureLevels').'</a>';
	}
	print '</div>';
	print '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

// Level names summary
print '<div class="opacitymedium marginbottomonly small">';
print $langs->trans('WarehouseLevels').': ';
$label_strs = array();
foreach ($wh_levels as $cfg) {
	$label_strs[] = dol_escape_htmltag($cfg->label);
}
print implode(' &rarr; ', $label_strs);
print '</div>';

// Search bar: product search + per-level bin filters (explore by bin)
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="id" value="'.$id.'">';
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
			print '<option value="'.(int) $opt->id.'"'.$sel.'>'.dol_escape_htmltag($opt->value.$suffix).'</option>';
		}
		print '</select>';
	} else {
		print ' <input type="text" name="search_level'.$level_id.'" class="flat width75" value="'.dol_escape_htmltag($raw).'" placeholder="'.dol_escape_htmltag($cfg->label).'">';
	}
}
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Search').'">';
if (!empty($search) || $has_filters) {
	print ' <a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'" class="button smallpaddingimp">'.$langs->trans('Reset').'</a>';
}
print '</div>';
print '</form>';

$locations = $locObj->fetchAllByWarehouse($id, $search, $sortfield, $sortorder, $limit, $offset, $level_filters);
$total     = $locObj->countByWarehouse($id, $search, $level_filters);

$list_param = '&id='.$id.(!empty($search) ? '&search_product='.urlencode($search) : '').$filter_param;

if (!empty($locations) || !empty($search) || $has_filters) {
	print_barre_liste(
		$langs->trans('ProductsInWarehouse', $object->ref),
		$page,
		$_SERVER['PHP_SELF'],
		$list_param,
		$sortfield,
		$sortorder,
		'',
		count($locations),
		$total,
		'',
		0,
		'',
		'',
		$limit
	);

	$has_lots = false;
	foreach ($locations as $loc) {
		if (!empty($loc->lot_batch)) {
			$has_lots = true;
			break;
		}
	}

	print '<table class="noborder centpercent" id="binloc-wh-table">';
	print '<tr class="liste_titre">';
	print_liste_field_titre('ProductRef', $_SERVER['PHP_SELF'], 'p.ref', '', $list_param, '', $sortfield, $sortorder);
	print_liste_field_titre('Label', $_SERVER['PHP_SELF'], 'p.label', '', $list_param, '', $sortfield, $sortorder);
	if ($has_lots) {
		print '<td>'.$langs->trans('Lot').'/'.$langs->trans('Serial').'</td>';
	}
	print '<td class="right">'.$langs->trans('Stock').'</td>';
	foreach ($wh_levels as $cfg) {
		print '<td>'.dol_escape_htmltag($cfg->label).'</td>';
	}
	print '<td>'.$langs->trans('LocationNote').'</td>';
	print '<td class="center">'.$langs->trans('Actions').'</td>';
	print '</tr>';

	if (empty($locations)) {
		$colspan = 5 + count($wh_levels) + ($has_lots ? 1 : 0);
		print '<tr class="oddeven"><td colspan="'.$colspan.'" class="opacitymedium">'.$langs->trans('NoResult').'</td></tr>';
	}

	foreach ($locations as $loc) {
		$product_url = dol_buildpath('/product/card.php?id='.$loc->fk_product, 1);

		print '<tr class="oddeven" data-fk-product="'.$loc->fk_product.'" data-fk-entrepot="'.$id.'"';
		print ' data-loc-id="'.$loc->rowid.'" data-fk-lot="'.$loc->fk_product_lot.'" data-note="'.dol_escape_htmltag((string) $loc->note).'">';
		print '<td><a href="'.$product_url.'">'.dol_escape_htmltag($loc->product_ref).'</a></td>';
		print '<td>'.dol_escape_htmltag($loc->product_label).'</td>';
		if ($has_lots) {
			print '<td>'.(!empty($loc->lot_batch) ? dol_escape_htmltag($loc->lot_batch) : '').'</td>';
		}
		print '<td class="right">'.price2num($loc->stock, 0).'</td>';
		foreach ($wh_levels as $level_id => $cfg) {
			$display = isset($loc->values[$level_id]) ? $loc->values[$level_id]->display : '';
			print '<td class="binloc-val-cell" data-level="'.$level_id.'">';
			print ($display !== null && $display !== '') ? dol_escape_htmltag($display) : '<span class="opacitymedium">&mdash;</span>';
			print '</td>';
		}
		print '<td class="binloc-note-cell">'.($loc->note ? dol_escape_htmltag($loc->note) : '').'</td>';
		print '<td class="center nowraponall binloc-actions-cell">';
		if ($can_write) {
			print '<a href="#" class="binloc-row-edit">'.img_picto($langs->trans('EditLocation'), 'edit').'</a> ';
			print '<a href="#" class="binloc-row-delete">'.img_picto($langs->trans('RemoveLocation'), 'delete').'</a>';
		}
		print '</td>';
		print '</tr>';
	}

	print '</table>';
} else {
	print '<div class="opacitymedium">'.$langs->trans('NoProductsWithStock').'</div>';
}

// Links to bulk assign / level setup
if ($can_write) {
	$bulk_url = dol_buildpath('/binloc/bulk_assign.php?fk_entrepot='.$id, 1);
	print '<div class="margintoponly">';
	print '<a href="'.$bulk_url.'" class="button">';
	print img_picto('', 'edit', 'class="pictofixedwidth"').$langs->trans('BulkBinAssign');
	print '</a>';

	if ($user->hasRight('binloc', 'admin') || $user->admin) {
		$setup_url = dol_buildpath('/binloc/admin/warehouse_levels.php?fk_entrepot='.$id, 1);
		print ' <a href="'.$setup_url.'" class="button">';
		print img_picto('', 'setup', 'class="pictofixedwidth"').$langs->trans('ManageLevels');
		print '</a>';
	}
	$export_url = dol_buildpath('/binloc/admin/import_export.php', 1).'?action=export_assign&fk_entrepot='.$id;
	print ' <a href="'.$export_url.'" class="button">';
	print img_picto('', 'download', 'class="pictofixedwidth"').$langs->trans('CsvExportAssignments');
	print '</a>';
	print '</div>';
}

print '</div>';
print dol_get_fiche_end();

if ($can_write) {
	print '<script>
jQuery(function ($) {
	Binloc.config.msgSaved = "'.dol_escape_js($langs->trans('LocationSaved')).'";
	Binloc.config.msgRemoved = "'.dol_escape_js($langs->trans('LocationRemoved')).'";
	var $table = $("#binloc-wh-table");

	// Inline edit: distribute the server-rendered inputs into the level cells
	$table.on("click", ".binloc-row-edit", function (e) {
		e.preventDefault();
		var $row = $(this).closest("tr");
		if ($row.hasClass("binloc-editing")) { return; }
		var prefix = "wh" + $row.data("fk-product") + "_";
		Binloc.fetchLevels($row.data("fk-entrepot"), prefix, $row.data("loc-id"), function (err, data) {
			if (err) { Binloc.toast(err, true); return; }
			$row.addClass("binloc-editing");
			var $stash = $("<div>").html(data.html);
			$row.find(".binloc-val-cell").each(function () {
				var $input = $stash.find(".binloc-level-input").filter("[data-level=\'" + $(this).data("level") + "\']");
				$(this).data("binloc-orig", $(this).html());
				$(this).empty().append($input.length ? $input : "");
			});
			var $noteCell = $row.find(".binloc-note-cell");
			$noteCell.data("binloc-orig", $noteCell.html());
			$noteCell.empty().append($("<input type=\"text\" class=\"flat width100 binloc-note-input\">").val($row.data("note") || ""));
			var $actions = $row.find(".binloc-actions-cell");
			$actions.data("binloc-orig", $actions.html());
			$actions.empty()
				.append($("<a href=\"#\" class=\"binloc-row-save\">").append("'.dol_escape_js(img_picto('', 'save')).'"))
				.append(" ")
				.append($("<a href=\"#\" class=\"binloc-row-cancel\">").append("'.dol_escape_js(img_picto('', 'cancel')).'"));
		});
	});

	function restoreRow($row) {
		$row.find(".binloc-val-cell, .binloc-note-cell, .binloc-actions-cell").each(function () {
			if ($(this).data("binloc-orig") !== undefined) {
				$(this).html($(this).data("binloc-orig")).removeData("binloc-orig");
			}
		});
		$row.removeClass("binloc-editing");
	}

	$table.on("click", ".binloc-row-cancel", function (e) {
		e.preventDefault();
		restoreRow($(this).closest("tr"));
	});

	$table.on("click", ".binloc-row-save", function (e) {
		e.preventDefault();
		var $row = $(this).closest("tr");
		var params = {
			fk_product: $row.data("fk-product"),
			fk_entrepot: $row.data("fk-entrepot"),
			fk_product_lot: $row.data("fk-lot") || 0,
			note: $row.find(".binloc-note-input").val()
		};
		var displays = {};
		$row.find(".binloc-level-input").each(function () {
			params["binloc_level" + $(this).data("level")] = $(this).val();
			displays[$(this).data("level")] = this.tagName === "SELECT"
				? ($(this).val() ? $(this).find("option:selected").text() : "")
				: $(this).val();
		});
		Binloc.saveLocation(params, function (err, resp) {
			if (err) { return; }
			$row.data("loc-id", resp.id).data("note", params.note);
			$row.find(".binloc-val-cell").each(function () {
				var d = displays[$(this).data("level")];
				$(this).removeData("binloc-orig").html(d ? $("<span>").text(d) : "<span class=\"opacitymedium\">&mdash;</span>");
			});
			$row.find(".binloc-note-cell").removeData("binloc-orig").text(params.note || "");
			var $actions = $row.find(".binloc-actions-cell");
			$actions.html($actions.data("binloc-orig")).removeData("binloc-orig");
			$row.removeClass("binloc-editing");
		});
	});

	$table.on("click", ".binloc-row-delete", function (e) {
		e.preventDefault();
		var $row = $(this).closest("tr");
		Binloc.deleteLocation($row.data("loc-id"), "'.dol_escape_js($langs->trans('ConfirmRemoveLocation', $object->ref)).'", function () {
			$row.remove();
		});
	});
});
</script>';
}

llxFooter();
$db->close();
