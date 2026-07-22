<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    tab_product_locations.php
 * \ingroup binloc
 * \brief   Product card tab — shows bin locations across all warehouses
 *
 * All mutations go through the Binloc AJAX endpoints (POST + CSRF token);
 * this page only renders state.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

$langs->loadLangs(array('products', 'stocks', 'binloc@binloc'));

$id  = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');

$object = new Product($db);
if ($id > 0 || !empty($ref)) {
	$object->fetch($id, $ref);
	$id = $object->id;
}

if (empty($id) || $id <= 0) {
	accessforbidden('Product not found');
}

$can_write = $user->hasRight('binloc', 'write');

$levelObj = new BinlocWarehouseLevel($db);
$locObj   = new BinlocProductLocation($db);

// ---- VIEW ----

llxHeader('', $langs->trans('BinLocations').' - '.$object->ref, binloc_help_url());

binloc_print_assets();

$head = product_prepare_head($object);
print dol_get_fiche_head($head, 'binloc', $langs->trans('Product'), -1, $object->picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1&type='.$object->type.'">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$locations = $locObj->fetchAllByProduct($id);

$located_wh_ids = array();
foreach ($locations as $loc) {
	$located_wh_ids[$loc->fk_entrepot] = true;
}

// Warehouses where the product has stock but no location yet
$unlocated_warehouses = array();
$sql = "SELECT ps.fk_entrepot, ps.reel as stock, e.ref, e.lieu";
$sql .= " FROM ".MAIN_DB_PREFIX."product_stock as ps";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
$sql .= " WHERE ps.fk_product = ".(int) $id;
$sql .= " AND ps.reel > 0";
$sql .= " AND e.entity IN (".getEntity('stock').")";
$sql .= " AND e.statut = 1";
$sql .= " ORDER BY e.ref ASC";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		if (!isset($located_wh_ids[(int) $obj->fk_entrepot])) {
			$unlocated_warehouses[] = $obj;
		}
	}
	$db->free($resql);
}

// Level configs for every involved warehouse in one query
$wh_ids = array_keys($located_wh_ids);
foreach ($unlocated_warehouses as $uwh) {
	$wh_ids[] = (int) $uwh->fk_entrepot;
}
$levels_by_wh = $levelObj->fetchByWarehouses($wh_ids);

// ---- Section 1: stock without a location ----
if (!empty($unlocated_warehouses) && $can_write) {
	print '<div class="underbanner marginbottomonly">';
	print '<strong>'.img_picto('', 'warning', 'class="pictofixedwidth"').$langs->trans('StockWithoutLocation').'</strong>';
	print '</div>';

	foreach ($unlocated_warehouses as $uwh) {
		$wh_id     = (int) $uwh->fk_entrepot;
		$wh_levels = isset($levels_by_wh[$wh_id]) ? $levels_by_wh[$wh_id] : array();
		$wh_url    = dol_buildpath('/product/stock/card.php?id='.$wh_id, 1);

		print '<div class="binloc-card binloc-unassigned" data-fk-product="'.$id.'" data-fk-entrepot="'.$wh_id.'" data-loc-id="0" data-fk-lot="0">';

		print '<div class="binloc-card-title">';
		print img_picto('', 'stock', 'class="pictofixedwidth"');
		print '<a href="'.$wh_url.'">'.dol_escape_htmltag($uwh->ref).'</a>';
		if ($uwh->lieu) {
			print ' <span class="opacitymedium">'.dol_escape_htmltag($uwh->lieu).'</span>';
		}
		print ' &mdash; '.$langs->trans('Stock').': '.price2num($uwh->stock, 0);
		print ' &mdash; <span class="opacitymedium small">'.$langs->trans('NoBinLocationAssigned').'</span>';
		print '</div>';

		print '<div class="binloc-loc-cell"><span class="binloc-loc-display"></span></div>';

		if (!empty($wh_levels)) {
			print '<a href="#" class="button smallpaddingimp binloc-edit-btn">';
			print img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('AssignLocation');
			print '</a>';
		} else {
			$setup_url = dol_buildpath('/binloc/admin/warehouse_levels.php?fk_entrepot='.$wh_id, 1);
			print '<a href="'.$setup_url.'" class="button smallpaddingimp">';
			print img_picto('', 'setup', 'class="pictofixedwidth"').$langs->trans('ConfigureLevels');
			print '</a>';
		}

		print '</div>';
	}
}

// ---- Section 2: assigned locations ----
if (!empty($locations)) {
	if (!empty($unlocated_warehouses)) {
		print '<div class="underbanner marginbottomonly margintoponly">';
		print '<strong>'.img_picto('', 'stock', 'class="pictofixedwidth"').$langs->trans('AssignedLocations').'</strong>';
		print '</div>';
	}

	foreach ($locations as $loc) {
		$wh_url = dol_buildpath('/product/stock/card.php?id='.$loc->fk_entrepot, 1);

		print '<div class="binloc-card" data-fk-product="'.$id.'" data-fk-entrepot="'.$loc->fk_entrepot.'"';
		print ' data-loc-id="'.$loc->rowid.'" data-fk-lot="'.$loc->fk_product_lot.'" data-note="'.dol_escape_htmltag((string) $loc->note).'">';

		print '<div class="binloc-card-title">';
		print img_picto('', 'stock', 'class="pictofixedwidth"');
		print '<a href="'.$wh_url.'">'.dol_escape_htmltag($loc->warehouse_ref).'</a>';
		if ($loc->warehouse_label) {
			print ' <span class="opacitymedium">'.dol_escape_htmltag($loc->warehouse_label).'</span>';
		}
		if (!empty($loc->lot_batch)) {
			print ' &mdash; <span class="badge badge-info">'.dol_escape_htmltag($loc->lot_batch).'</span>';
		}
		print ' &mdash; '.$langs->trans('Stock').': '.price2num($loc->stock, 0);
		print '</div>';

		print '<div class="binloc-loc-cell">';
		print '<span class="binloc-loc-display">'.dol_escape_htmltag($loc->location);
		if ($loc->note) {
			print ' <span class="opacitymedium small">('.dol_escape_htmltag($loc->note).')</span>';
		}
		print '</span>';
		print '</div>';

		if ($can_write) {
			print '<div class="margintoponly">';
			print '<a href="#" class="button smallpaddingimp binloc-edit-btn">'.$langs->trans('EditLocation').'</a> ';
			print '<a href="#" class="button smallpaddingimp binloc-delete-btn">'.$langs->trans('RemoveLocation').'</a>';
			print '</div>';
		}

		print '</div>';
	}
} elseif (empty($unlocated_warehouses)) {
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('NoLocationsFound').'</div>';
}

// ---- Section 3: add to any other warehouse ----
if ($can_write) {
	$exclude_wh_ids = $located_wh_ids;
	foreach ($unlocated_warehouses as $uwh) {
		$exclude_wh_ids[(int) $uwh->fk_entrepot] = true;
	}

	print '<div class="binloc-card margintoponly" id="binloc-add-card" data-fk-product="'.$id.'">';
	print '<div class="binloc-card-title">'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('AddToOtherWarehouse').'</div>';

	print '<div class="binloc-inline-form">';
	print '<select id="binloc-add-wh" class="flat minwidth250">';
	print '<option value="">'.$langs->trans('SelectWarehouse').'</option>';
	foreach (binloc_get_warehouses($db) as $wh) {
		if (isset($exclude_wh_ids[(int) $wh->rowid])) {
			continue;
		}
		print '<option value="'.(int) $wh->rowid.'">'.dol_escape_htmltag($wh->ref.($wh->lieu ? ' - '.$wh->lieu : '')).'</option>';
	}
	print '</select>';
	print '<span id="binloc-add-levels"></span>';
	print '<input type="text" id="binloc-add-note" class="flat minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('LocationNote')).'">';
	print '<button type="button" class="button smallpaddingimp" id="binloc-add-save">'.$langs->trans('Save').'</button>';
	print '</div>';
	print '</div>';
}

print '</div>';
print dol_get_fiche_end();

if ($can_write) {
	print '<script>
jQuery(function ($) {
	Binloc.bindInlineEdit($(".fichecenter"), {
		save: "'.dol_escape_js($langs->trans('Save')).'",
		cancel: "'.dol_escape_js($langs->trans('Cancel')).'",
		notePlaceholder: "'.dol_escape_js($langs->trans('LocationNote')).'",
		confirmDelete: "'.dol_escape_js($langs->trans('ConfirmRemoveLocation', '')).'"
	});
	Binloc.config.msgSaved = "'.dol_escape_js($langs->trans('LocationSaved')).'";
	Binloc.config.msgRemoved = "'.dol_escape_js($langs->trans('LocationRemoved')).'";

	// Section 1 assigns restructure the page — reload after a successful save
	$(".binloc-unassigned").on("binloc:saved", function () {
		window.location.reload();
	});

	// Add-to-other-warehouse card
	$("#binloc-add-wh").on("change", function () {
		Binloc.swapLevelInputs($("#binloc-add-levels"), $(this).val(), "add_", 0);
	});
	$("#binloc-add-save").on("click", function () {
		var whId = $("#binloc-add-wh").val();
		if (!whId) { return; }
		var params = $.extend({
			fk_product: '.$id.',
			fk_entrepot: whId,
			fk_product_lot: 0,
			note: $("#binloc-add-note").val()
		}, Binloc.collectLevelValues($("#binloc-add-levels"), "add_"));
		Binloc.saveLocation(params, function () {
			window.location.reload();
		});
	});
});
</script>';
}

llxFooter();
$db->close();
