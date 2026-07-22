<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    admin/setup.php
 * \ingroup binloc
 * \brief   Binloc module general settings
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/class/binlocmigration.class.php');

$langs->loadLangs(array('admin', 'binloc@binloc'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ---- ACTIONS ----

$migration = new BinlocMigration($db);

if ($action === 'runmigration') {
	// Token is enforced automatically by main.inc.php for POSTed actions
	$result = $migration->run();
	if ($result > 0) {
		setEventMessages($langs->trans('BinlocMigrationDone'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('BinlocMigrationFailed'), null, 'errors');
	}
}

$migration_status = $migration->getStatus();

// ---- VIEW ----

$page_name = 'BinlocSetup';
llxHeader('', $langs->trans($page_name), binloc_help_url());

$head = binloc_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, 'stock');

// Migration status banner
if ($migration_status->state === 'ok') {
	print '<div class="ok binloc-migration-banner">';
	print img_picto('', 'tick').' '.$langs->trans('BinlocDbUpToDate', $migration_status->version);
	print '</div>';
} else {
	$is_failed = ($migration_status->state === 'failed');
	print '<div class="'.($is_failed ? 'error' : 'warning').' binloc-migration-banner">';
	if ($is_failed) {
		print $langs->trans('BinlocMigrationFailedAt', dol_escape_htmltag($migration_status->error));
	} else {
		print $langs->trans('BinlocMigrationPending', $migration_status->version ?: '1.x', $migration_status->target);
	}
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="runmigration">';
	print ' <input type="submit" class="button small" value="'.$langs->trans($is_failed ? 'BinlocMigrationRetry' : 'BinlocMigrationRun').'">';
	print '</form>';
	print '</div>';
}
if (!empty($migration_status->report)) {
	print '<div class="opacitymedium binloc-migration-report">';
	$parts = array();
	foreach ($migration_status->report as $k => $v) {
		$parts[] = dol_escape_htmltag($k.': '.(is_array($v) ? json_encode($v) : $v));
	}
	print $langs->trans('BinlocMigrationReport').': '.implode(', ', $parts);
	print '</div>';
}
print '<br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Parameter').'</td>';
print '<td class="center" width="100">'.$langs->trans('Value').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '</tr>';

// Clear on zero stock
print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClearOnZeroStock').'</td>';
print '<td class="center">';
print ajax_constantonoff('BINLOC_CLEAR_ON_ZERO_STOCK');
print '</td>';
print '<td class="opacitymedium">'.$langs->trans('ClearOnZeroStockDesc').'</td>';
print '</tr>';

// Debug mode
print '<tr class="oddeven">';
print '<td>'.$langs->trans('DebugMode').'</td>';
print '<td class="center">';
print ajax_constantonoff('BINLOC_DEBUG_MODE');
print '</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td>';
print '</tr>';

print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
