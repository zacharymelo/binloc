<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    bulk_assign.php
 * \ingroup binloc
 * \brief   Bulk assign bin locations for all products with stock in a warehouse
 *
 * Uses the shared bulk renderer (lib/binloc_bulk.lib.php); saving goes through
 * ajax/batch_save.php with only-fill-non-empty semantics and explicit per-row
 * clearing. The batch-set panel fills checked rows client-side after a
 * confirmation that names the fields and the row count.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/binloc/lib/binloc_bulk.lib.php');

$langs->loadLangs(array('products', 'stocks', 'binloc@binloc'));

if (!$user->hasRight('binloc', 'write')) {
	accessforbidden();
}

$fk_entrepot = GETPOSTINT('fk_entrepot');
$search      = GETPOST('search_product', 'alphanohtml');

// Pagination
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'p.ref';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page      = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
$limit     = GETPOSTINT('limit') ?: $conf->liste_limit;
$offset    = $limit * max(0, $page);

$levelObj = new BinlocWarehouseLevel($db);

// ---- VIEW ----

llxHeader('', $langs->trans('BulkBinAssign'), '');

binloc_print_assets();

print dol_get_fiche_head(array(), '', $langs->trans('BulkBinAssign'), -1, 'stock');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('BulkAssignDesc').'</div>';

// Warehouse selector
print '<div class="marginbottomonly">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
print '<strong>'.$langs->trans('Warehouse').'</strong>: ';
print binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot, 'flat minwidth250', 'onchange="this.form.submit()"');
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Select').'">';
print '</form>';
print '</div>';

if ($fk_entrepot > 0) {
	$wh_levels = $levelObj->fetchByWarehouse($fk_entrepot);

	if (empty($wh_levels)) {
		print '<div class="info">';
		print $langs->trans('NoLevelsConfigured').' ';
		if ($user->hasRight('binloc', 'admin') || $user->admin) {
			$setup_url = dol_buildpath('/binloc/admin/warehouse_levels.php?fk_entrepot='.$fk_entrepot, 1);
			print '<a href="'.$setup_url.'" class="button smallpaddingimp">'.$langs->trans('ConfigureLevels').'</a>';
		}
		print '</div>';
	} else {
		// Search bar
		print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
		print '<div class="marginbottomonly">';
		print '<input type="text" name="search_product" class="flat minwidth200" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('SearchProduct')).'">';
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Search').'">';
		if (!empty($search)) {
			print ' <a href="'.$_SERVER['PHP_SELF'].'?fk_entrepot='.$fk_entrepot.'" class="button smallpaddingimp">'.$langs->trans('Reset').'</a>';
		}
		print '</div>';
		print '</form>';

		$rows  = binloc_bulk_rows_from_warehouse($db, $fk_entrepot, $search, $sortfield, $sortorder, $limit, $offset);
		$total = binloc_count_products_in_warehouse($db, $fk_entrepot, $search);

		if (!empty($rows)) {
			// Level names summary
			print '<div class="opacitymedium marginbottomonly small">';
			$label_strs = array();
			foreach ($wh_levels as $cfg) {
				$label_strs[] = dol_escape_htmltag($cfg->label);
			}
			print implode(' &rarr; ', $label_strs);
			print '</div>';

			print_barre_liste(
				'',
				$page,
				$_SERVER['PHP_SELF'],
				'&fk_entrepot='.$fk_entrepot.(!empty($search) ? '&search_product='.urlencode($search) : ''),
				$sortfield,
				$sortorder,
				'',
				count($rows),
				$total,
				'',
				0,
				'',
				'',
				$limit
			);

			binloc_render_bulk_table($db, $rows, array($fk_entrepot => $wh_levels), array(
				'table_id'         => 'binloc-bulk-table',
				'warehouse_select' => false,
				'checkboxes'       => true,
				'qty_label'        => $langs->trans('Stock'),
			));

			print '<div class="margintoponly">';
			print '<button type="button" class="button" id="binloc-bulk-save">'.dol_escape_htmltag($langs->trans('BulkSaveAll')).'</button>';
			print '</div>';

			// ---- Batch set panel (fills checked rows client-side, then Save All persists) ----
			print '<div class="binloc-batch-panel" id="binloc-batch-panel">';
			print '<strong>'.$langs->trans('SetSelectedTo').':</strong>';
			print '<div class="binloc-inline-form margintoponly">';
			print binloc_render_level_inputs($wh_levels, 'batch_', array(), 'flat width75 binloc-level-input');
			print '<button type="button" class="button smallpaddingimp" id="binloc-batch-apply">'.dol_escape_htmltag($langs->trans('Apply')).'</button>';
			print '</div>';
			print '</div>';

			print '<script>
jQuery(function ($) {
	Binloc.config.msgBulkSaved = "'.dol_escape_js($langs->trans('BulkSaved', '%s')).'";
	Binloc.config.msgNothing = "'.dol_escape_js($langs->trans('BulkNothingChanged')).'";
	var $table = $("#binloc-bulk-table");
	var $panel = $("#binloc-batch-panel");
	Binloc.bindBulkTable($table);

	$("#binloc-bulk-save").on("click", function () {
		Binloc.saveBulkTable($table);
	});

	$table.on("change", ".binloc-row-check, .binloc-check-all", function () {
		$panel.toggleClass("active", $table.find(".binloc-row-check:checked").length > 0);
	});

	$("#binloc-batch-apply").on("click", function () {
		Binloc.applyBatchPanel($panel, $table, {
			confirmBatch: "'.dol_escape_js($langs->trans('ConfirmBatchSet')).'",
			nothingToApply: "'.dol_escape_js($langs->trans('BulkNothingChanged')).'"
		});
	});
});
</script>';
		} else {
			print '<div class="opacitymedium">'.$langs->trans('NoProductsWithStock').'</div>';
		}
	}
}

print dol_get_fiche_end();
llxFooter();
$db->close();
