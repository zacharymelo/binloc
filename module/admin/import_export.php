<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    admin/import_export.php
 * \ingroup binloc
 * \brief   CSV import/export for warehouse layouts and bin assignments
 *
 * Import is a two-step flow: upload -> dry-run preview (planned actions and
 * errors, nothing written) -> confirm. Apply is all-or-nothing in a
 * transaction. Export output is directly re-importable.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/lib/binloc_csv.lib.php');

$langs->loadLangs(array('admin', 'stocks', 'binloc@binloc'));

if (!$user->hasRight('binloc', 'read') && !$user->admin) {
	accessforbidden();
}

$can_write  = $user->hasRight('binloc', 'write');
$can_admin  = $user->hasRight('binloc', 'admin') || $user->admin;

$action      = GETPOST('action', 'aZ09');
$fk_entrepot = GETPOSTINT('fk_entrepot');

$tempdir = DOL_DATA_ROOT.'/binloc/temp';

/**
 * Store an uploaded CSV in the module temp dir under a random token name
 *
 * @param  string $tempdir Temp directory
 * @return string          Token filename, or '' on failure
 */
function binloc_ie_store_upload($tempdir)
{
	if (empty($_FILES['csvfile']['tmp_name']) || !is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
		return '';
	}
	dol_mkdir($tempdir);
	$token = 'import_'.bin2hex(random_bytes(8)).'.csv';
	if (!move_uploaded_file($_FILES['csvfile']['tmp_name'], $tempdir.'/'.$token)) {
		return '';
	}
	return $token;
}

/**
 * Validate a stored-upload token and return its path
 *
 * @param  string $tempdir Temp directory
 * @param  string $token   Token filename from the confirm form
 * @return string          Full path, or '' if invalid/missing
 */
function binloc_ie_token_path($tempdir, $token)
{
	if (!preg_match('/^import_[a-f0-9]{16}\.csv$/', $token)) {
		return '';
	}
	$path = $tempdir.'/'.$token;
	return file_exists($path) ? $path : '';
}

/**
 * Render an import report (summary counts, errors, planned/applied actions)
 *
 * @param  array $report  Report from a binloc_*_import_run() call
 * @param  bool  $applied true when the report is the result of an apply
 * @return void
 */
function binloc_ie_print_report($report, $applied)
{
	global $langs;

	$parts = array();
	foreach ($report['counts'] as $k => $v) {
		if ($v > 0) {
			$parts[] = $langs->trans('CsvCount'.$k).': '.$v;
		}
	}
	print '<div class="'.($applied ? 'ok' : 'info').' marginbottomonly">';
	print $applied ? $langs->trans('CsvImportApplied') : $langs->trans('CsvImportPreview');
	print ($parts ? ' — '.implode(', ', $parts) : '');
	print '</div>';

	if (!empty($report['errors'])) {
		print '<div class="error marginbottomonly">';
		foreach (array_slice($report['errors'], 0, 50) as $err) {
			print dol_escape_htmltag($err).'<br>';
		}
		if (count($report['errors']) > 50) {
			print '… +'.(count($report['errors']) - 50);
		}
		print '</div>';
	}

	if (!empty($report['rows'])) {
		print '<table class="noborder centpercent marginbottomonly">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Line').'</td><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Action').'</td><td>'.$langs->trans('Details').'</td>';
		print '</tr>';
		foreach (array_slice($report['rows'], 0, 200) as $row) {
			print '<tr class="oddeven">';
			print '<td>'.($row['line'] ?: '').'</td>';
			$ref = isset($row['product']) ? $row['product'].(!empty($row['lot']) ? ' ('.$row['lot'].')' : '') : (isset($row['warehouse']) ? $row['warehouse'].' / '.$row['label'] : '');
			print '<td>'.dol_escape_htmltag($ref).'</td>';
			print '<td>'.dol_escape_htmltag($langs->trans('CsvAction'.$row['action'])).'</td>';
			print '<td>'.dol_escape_htmltag($row['detail']).'</td>';
			print '</tr>';
		}
		if (count($report['rows']) > 200) {
			print '<tr class="oddeven"><td colspan="4" class="opacitymedium">… +'.(count($report['rows']) - 200).'</td></tr>';
		}
		print '</table>';
	}
}

// ---- Export actions (GET, read permission) ----

if ($action === 'export_layout') {
	binloc_csv_output('binloc-layout'.($fk_entrepot > 0 ? '-'.$fk_entrepot : '').'.csv', binloc_layout_export_lines($db, $fk_entrepot));
}
if ($action === 'export_assign' && $fk_entrepot > 0) {
	binloc_csv_output('binloc-assignments-'.$fk_entrepot.'.csv', binloc_assign_export_lines($db, $fk_entrepot));
}

// ---- Import actions (POST + token; enforced by main.inc.php) ----

$preview = null;      // array('kind' =>, 'report' =>, 'token' =>, 'fk_entrepot' =>, 'create_missing' =>)
$applied_report = null;
$applied_kind = '';

if ($action === 'preview_layout' && $can_admin) {
	$token = binloc_ie_store_upload($tempdir);
	$parsed = $token ? binloc_csv_read($tempdir.'/'.$token) : false;
	if (!$parsed) {
		setEventMessages($langs->trans('CsvUploadFailed'), null, 'errors');
	} else {
		$preview = array('kind' => 'layout', 'report' => binloc_layout_import_run($db, $parsed, $user, false), 'token' => $token, 'fk_entrepot' => 0, 'create_missing' => 0);
	}
}

if ($action === 'apply_layout' && $can_admin) {
	$path = binloc_ie_token_path($tempdir, GETPOST('csvtoken', 'alphanohtml'));
	$parsed = $path ? binloc_csv_read($path) : false;
	if (!$parsed) {
		setEventMessages($langs->trans('CsvUploadFailed'), null, 'errors');
	} else {
		$applied_report = binloc_layout_import_run($db, $parsed, $user, true);
		$applied_kind = 'layout';
		unlink($path);
		if (empty($applied_report['errors'])) {
			setEventMessages($langs->trans('CsvImportDone'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('CsvImportFailed'), null, 'errors');
		}
	}
}

if ($action === 'preview_assign' && $can_write && $fk_entrepot > 0) {
	$create_missing = GETPOSTINT('create_missing');
	$token = binloc_ie_store_upload($tempdir);
	$parsed = $token ? binloc_csv_read($tempdir.'/'.$token) : false;
	if (!$parsed) {
		setEventMessages($langs->trans('CsvUploadFailed'), null, 'errors');
	} else {
		$preview = array('kind' => 'assign', 'report' => binloc_assign_import_run($db, $fk_entrepot, $parsed, $create_missing, $user, false), 'token' => $token, 'fk_entrepot' => $fk_entrepot, 'create_missing' => $create_missing);
	}
}

if ($action === 'apply_assign' && $can_write && $fk_entrepot > 0) {
	$create_missing = GETPOSTINT('create_missing');
	$path = binloc_ie_token_path($tempdir, GETPOST('csvtoken', 'alphanohtml'));
	$parsed = $path ? binloc_csv_read($path) : false;
	if (!$parsed) {
		setEventMessages($langs->trans('CsvUploadFailed'), null, 'errors');
	} else {
		$applied_report = binloc_assign_import_run($db, $fk_entrepot, $parsed, $create_missing, $user, true);
		$applied_kind = 'assign';
		unlink($path);
		if (empty($applied_report['errors'])) {
			setEventMessages($langs->trans('CsvImportDone'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('CsvImportFailed'), null, 'errors');
		}
	}
}

// ---- VIEW ----

$page_name = 'BinlocSetup';
llxHeader('', $langs->trans($page_name), binloc_help_url());

$head = binloc_admin_prepare_head();
print dol_get_fiche_head($head, 'importexport', $langs->trans($page_name), -1, 'stock');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CsvPageDesc').'</div>';

// Pending preview: show report + confirm button
if ($preview !== null) {
	binloc_ie_print_report($preview['report'], false);

	$has_errors = !empty($preview['report']['errors']);
	if ($has_errors) {
		print '<div class="warning marginbottomonly">'.$langs->trans('CsvFixErrorsFirst').'</div>';
	} else {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="apply_'.$preview['kind'].'">';
		print '<input type="hidden" name="csvtoken" value="'.dol_escape_htmltag($preview['token']).'">';
		print '<input type="hidden" name="fk_entrepot" value="'.(int) $preview['fk_entrepot'].'">';
		print '<input type="hidden" name="create_missing" value="'.(int) $preview['create_missing'].'">';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CsvConfirmImport')).'">';
		print ' <a href="'.$_SERVER['PHP_SELF'].'" class="button button-cancel">'.$langs->trans('Cancel').'</a>';
		print '</form>';
	}
	print '<hr>';
}

// Result of an apply
if ($applied_report !== null) {
	binloc_ie_print_report($applied_report, empty($applied_report['errors']));
	print '<hr>';
}

// ---- Layout section ----
print '<div class="binloc-card">';
print '<div class="binloc-card-title">'.$langs->trans('CsvLayoutSection').'</div>';
print '<div class="opacitymedium small marginbottomonly">'.$langs->trans('CsvLayoutDesc').'</div>';

print '<div class="marginbottomonly">';
print '<a href="'.$_SERVER['PHP_SELF'].'?action=export_layout" class="button smallpaddingimp">'.$langs->trans('CsvExportAllLayouts').'</a>';
print '</div>';

if ($can_admin) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="preview_layout">';
	print '<input type="file" name="csvfile" accept=".csv,text/csv" required>';
	print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvPreviewImport')).'">';
	print '</form>';
}
print '</div>';

// ---- Assignments section ----
print '<div class="binloc-card">';
print '<div class="binloc-card-title">'.$langs->trans('CsvAssignSection').'</div>';
print '<div class="opacitymedium small marginbottomonly">'.$langs->trans('CsvAssignDesc').'</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly">';
print '<input type="hidden" name="action" value="export_assign">';
print binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot);
print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvExportAssignments')).'">';
print '</form>';

if ($can_write) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="preview_assign">';
	print binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot);
	print ' <input type="file" name="csvfile" accept=".csv,text/csv" required>';
	print ' <label><input type="checkbox" name="create_missing" value="1"> '.$langs->trans('CsvCreateMissingValues').'</label>';
	print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvPreviewImport')).'">';
	print '</form>';
}
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
