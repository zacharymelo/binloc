<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    tab_mo_locations.php
 * \ingroup binloc
 * \brief   Manufacturing Order tab — bulk-assign bin locations for serials produced by this MO
 *
 * Thin shell over the shared bulk renderer (lib/binloc_bulk.lib.php);
 * saving goes through ajax/batch_save.php.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/lib/mrp_mo.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/binloc/lib/binloc_bulk.lib.php');

$langs->loadLangs(array('mrp', 'products', 'stocks', 'productbatch', 'binloc@binloc'));

$id = GETPOSTINT('id');

$object = new Mo($db);
if ($id > 0) {
	$object->fetch($id);
}

if (empty($object->id) || $object->id <= 0) {
	accessforbidden('MO not found');
}

$can_write = $user->hasRight('binloc', 'write');

// ---- VIEW ----

llxHeader('', $langs->trans('BinLocations').' - '.$object->ref, '');

binloc_print_assets();

$head = moPrepareHead($object);
print dol_get_fiche_head($head, 'binloc', $langs->trans('ManufacturingOrder'), -1, $object->picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/mrp/mo_list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$rows = binloc_bulk_rows_from_mo($db, $object);

if (empty($rows)) {
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('NoSerializedOutputFromMo').'</div>';
	print '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('MoBinAssignDesc').'</div>';

$wh_ids = array();
foreach ($rows as $row) {
	if ($row->fk_entrepot > 0) {
		$wh_ids[] = $row->fk_entrepot;
	}
}
$levelObj = new BinlocWarehouseLevel($db);
$levels_by_wh = $levelObj->fetchByWarehouses(array_unique($wh_ids));

binloc_render_bulk_table($db, $rows, $levels_by_wh, array(
	'table_id'         => 'binloc-mo-table',
	'warehouse_select' => true,
	'qty_label'        => $langs->trans('Qty'),
));

if ($can_write) {
	print '<div class="margintoponly">';
	print '<button type="button" class="button" id="binloc-bulk-save">'.dol_escape_htmltag($langs->trans('BulkSaveAll')).'</button>';
	print '</div>';

	print '<script>
jQuery(function ($) {
	Binloc.config.msgBulkSaved = "'.dol_escape_js($langs->trans('BulkSaved', '%s')).'";
	Binloc.config.msgNothing = "'.dol_escape_js($langs->trans('BulkNothingChanged')).'";
	var $table = $("#binloc-mo-table");
	Binloc.bindBulkTable($table);
	$("#binloc-bulk-save").on("click", function () {
		Binloc.saveBulkTable($table);
	});
});
</script>';
}

print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
