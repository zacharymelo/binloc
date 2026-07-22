<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    tab_reception_locations.php
 * \ingroup binloc
 * \brief   Reception card tab — bulk-assign bin locations for this reception's lines
 *
 * Thin shell over the shared bulk renderer (lib/binloc_bulk.lib.php);
 * saving goes through ajax/batch_save.php.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/reception.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/binloc/lib/binloc_bulk.lib.php');

$langs->loadLangs(array('receptions', 'products', 'stocks', 'productbatch', 'binloc@binloc'));

$id = GETPOSTINT('id');

$object = new Reception($db);
if ($id > 0) {
	$object->fetch($id);
	$object->fetch_lines();
}

if (empty($object->id) || $object->id <= 0) {
	accessforbidden('Reception not found');
}

$can_write = $user->hasRight('binloc', 'write');

// ---- VIEW ----

llxHeader('', $langs->trans('BinPlacement').' - '.$object->ref, binloc_help_url());

binloc_print_assets();

$head = reception_prepare_head($object);
print dol_get_fiche_head($head, 'binloc', $langs->trans('Reception'), -1, $object->picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/reception/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$rows = empty($object->lines) ? array() : binloc_bulk_rows_from_reception($db, $object);

if (empty($rows)) {
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('NoReceptionLines').'</div>';
	print '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('ReceptionBinPlacementDesc').'</div>';

// Level configs for every destination warehouse in one query
$wh_ids = array();
foreach ($rows as $row) {
	if ($row->fk_entrepot > 0) {
		$wh_ids[] = $row->fk_entrepot;
	}
}
$levelObj = new BinlocWarehouseLevel($db);
$levels_by_wh = $levelObj->fetchByWarehouses(array_unique($wh_ids));

binloc_render_bulk_table($db, $rows, $levels_by_wh, array(
	'table_id'         => 'binloc-reception-table',
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
	var $table = $("#binloc-reception-table");
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
