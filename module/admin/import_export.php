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
 * The uploaded file's own name, for showing which file a preview is of
 *
 * @return string
 */
function binloc_ie_upload_name()
{
	return empty($_FILES['csvfile']['name']) ? '' : dol_sanitizeFileName(basename($_FILES['csvfile']['name']));
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
 * Actions that write something (the rest are informational)
 *
 * @return string[]
 */
function binloc_ie_change_actions()
{
	return array('create', 'update', 'clear', 'value_add', 'create_level', 'update_level', 'add_value');
}

/**
 * Number of rows/values an import report would write (or wrote)
 *
 * @param  array $report Report from a binloc_*_import_run() call
 * @return int
 */
function binloc_ie_change_count($report)
{
	$n = 0;
	foreach (binloc_ie_change_actions() as $action) {
		$n += isset($report['counts'][$action]) ? (int) $report['counts'][$action] : 0;
	}
	return $n;
}

/**
 * Badge for a report row action: the theme's semantic colours plus a word,
 * so the meaning never rests on colour alone
 *
 * @param  string $action Row action
 * @return string         HTML
 */
function binloc_ie_badge($action)
{
	global $langs;

	$styles = array(
		'create' => 'success', 'create_level' => 'success',
		'update' => 'warning', 'update_level' => 'warning',
		'clear' => 'danger',
		'value_add' => 'primary', 'add_value' => 'primary',
	);
	$style = isset($styles[$action]) ? $styles[$action] : 'secondary';
	return '<span class="badge badge-'.$style.' binloc-ie-badge">'.dol_escape_htmltag($langs->trans('CsvAction'.$action)).'</span>';
}

/**
 * Render a row's field changes: "Field: old → new", one per line
 *
 * @param  array $changes [field, from, to] triples ('' = empty)
 * @return string         HTML
 */
function binloc_ie_changes_html($changes)
{
	$out = array();
	foreach ($changes as $c) {
		list($field, $from, $to) = $c;
		$html = '<span class="binloc-ie-field">'.dol_escape_htmltag($field).'</span> ';
		if ($from !== '' && $to !== '') {
			$html .= '<span class="binloc-ie-old">'.dol_escape_htmltag($from).'</span> <span class="binloc-ie-arrow">→</span> <span class="binloc-ie-new">'.dol_escape_htmltag($to).'</span>';
		} elseif ($to !== '') {
			$html .= '<span class="binloc-ie-new">'.dol_escape_htmltag($to).'</span>';
		} else {
			$html .= '<span class="binloc-ie-old">'.dol_escape_htmltag($from).'</span>';
		}
		$out[] = '<div>'.$html.'</div>';
	}
	return implode('', $out);
}

/**
 * Print report rows as a table
 *
 * @param  array  $rows Report rows
 * @param  string $kind 'layout' or 'assign'
 * @param  int    $max  Rows shown before "… +N more"
 * @return void
 */
function binloc_ie_print_rows($rows, $kind, $max = 300)
{
	global $langs;

	print '<table class="noborder centpercent binloc-ie-report">';
	print '<tr class="liste_titre">';
	print '<td class="width50">'.$langs->trans('CsvFileLine').'</td>';
	print '<td>'.$langs->trans($kind === 'layout' ? 'Warehouse' : 'CsvColProduct').'</td>';
	print '<td>'.$langs->trans($kind === 'layout' ? 'CsvLevel' : 'CsvColLot').'</td>';
	print '<td>'.$langs->trans('CsvWhat').'</td>';
	print '<td>'.$langs->trans('CsvDetails').'</td>';
	print '</tr>';
	foreach (array_slice($rows, 0, $max) as $row) {
		print '<tr class="oddeven">';
		print '<td class="opacitymedium">'.($row['line'] ?: '—').'</td>';
		print '<td class="nowraponall">'.dol_escape_htmltag($row['ref']).'</td>';
		print '<td class="nowraponall">'.dol_escape_htmltag($row['sub']).'</td>';
		print '<td class="nowraponall">'.binloc_ie_badge($row['action']).'</td>';
		print '<td>'.(!empty($row['changes']) ? binloc_ie_changes_html($row['changes']) : '<span class="opacitymedium">'.dol_escape_htmltag($row['detail']).'</span>').'</td>';
		print '</tr>';
	}
	if (count($rows) > $max) {
		print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans('CsvMoreRows', count($rows) - $max).'</td></tr>';
	}
	print '</table>';
}

/**
 * Render an import report: what state it is in, a count per kind of change,
 * the problems, the rows that change, and the rows that don't (folded away)
 *
 * @param  array  $report Report from a binloc_*_import_run() call
 * @param  string $kind   'layout' or 'assign'
 * @param  bool   $applied true when the report is the result of an apply
 * @return void
 */
function binloc_ie_print_report($report, $kind, $applied)
{
	global $langs;

	$errors = $report['errors'];
	$n_changes = binloc_ie_change_count($report);

	// State banner: one sentence saying what happened and what to do next
	if (!empty($errors)) {
		$class = 'error';
		$text = $langs->trans($applied ? 'CsvStateFailed' : 'CsvStateErrors', count($errors));
	} elseif ($applied) {
		$class = 'ok';
		$text = $n_changes ? $langs->trans('CsvStateApplied', $n_changes) : $langs->trans('CsvStateNothingApplied');
	} elseif ($n_changes) {
		$class = 'info';
		$text = $langs->trans('CsvStatePreview', $n_changes);
	} else {
		$class = 'info';
		$text = $langs->trans('CsvStateNoChanges');
	}
	print '<div class="'.$class.' binloc-ie-state">'.$text.'</div>';

	// A count per kind of change, as the same badges the table uses
	$chips = array();
	foreach ($report['counts'] as $action => $n) {
		if ($n > 0) {
			$chips[] = '<span class="binloc-ie-chip">'.binloc_ie_badge($action).' <b>'.((int) $n).'</b> '.dol_escape_htmltag($langs->trans('CsvCount'.$action)).'</span>';
		}
	}
	if ($chips) {
		print '<div class="binloc-ie-chips">'.implode('', $chips).'</div>';
	}

	if (!empty($errors)) {
		print '<div class="binloc-ie-errors">';
		print '<div class="binloc-ie-subtitle">'.$langs->trans('CsvProblems').'</div>';
		print '<ul>';
		foreach (array_slice($errors, 0, 50) as $err) {
			print '<li>'.dol_escape_htmltag($err).'</li>';
		}
		if (count($errors) > 50) {
			print '<li class="opacitymedium">'.$langs->trans('CsvMoreRows', count($errors) - 50).'</li>';
		}
		print '</ul>';
		print '</div>';
	}

	$changed = array();
	$quiet = array();
	foreach ($report['rows'] as $row) {
		if (in_array($row['action'], binloc_ie_change_actions(), true)) {
			$changed[] = $row;
		} else {
			$quiet[] = $row;
		}
	}

	// File order (a layout level's new values follow the level); usort is
	// stable on PHP 8, and "not in file" rows (line 0) keep their place last
	$by_line = function ($a, $b) {
		return ($a['line'] ?: PHP_INT_MAX) <=> ($b['line'] ?: PHP_INT_MAX);
	};
	usort($changed, $by_line);
	usort($quiet, $by_line);

	if ($changed) {
		print '<div class="binloc-ie-subtitle">'.$langs->trans($applied ? 'CsvChangesWritten' : 'CsvChangesToWrite').'</div>';
		binloc_ie_print_rows($changed, $kind);
	}
	if ($quiet) {
		// Unfolded only when they are the whole story (no changes, no problems)
		print '<details class="binloc-ie-quiet"'.($changed || $errors ? '' : ' open').'>';
		print '<summary>'.$langs->trans('CsvQuietRows', count($quiet)).'</summary>';
		binloc_ie_print_rows($quiet, $kind);
		print '</details>';
	}
}


// ---- Export actions (GET, read permission) ----

if ($action === 'export_layout') {
	binloc_csv_output('binloc-layout'.($fk_entrepot > 0 ? '-'.$fk_entrepot : '').'.csv', binloc_layout_export_lines($db, $fk_entrepot));
}
if ($action === 'export_assign' && GETPOSTISSET('stock') && $fk_entrepot > 0) {
	binloc_csv_output('binloc-stock-bins-'.$fk_entrepot.'.csv', binloc_stock_export_lines($db, $fk_entrepot));
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
		$preview = array('kind' => 'layout', 'report' => binloc_layout_import_run($db, $parsed, $user, false), 'token' => $token, 'filename' => binloc_ie_upload_name(), 'fk_entrepot' => 0, 'create_missing' => 0);
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
		$preview = array('kind' => 'assign', 'report' => binloc_assign_import_run($db, $fk_entrepot, $parsed, $create_missing, $user, false), 'token' => $token, 'filename' => binloc_ie_upload_name(), 'fk_entrepot' => $fk_entrepot, 'create_missing' => $create_missing);
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

/**
 * Print one numbered step box (Export / Import) of a section card
 *
 * @param  int    $num   Step number
 * @param  string $title Step title
 * @param  string $hint  One-line explanation under the title
 * @param  string $body  Controls (HTML)
 * @return void
 */
function binloc_ie_step($num, $title, $hint, $body)
{
	print '<div class="binloc-ie-step">';
	print '<div class="binloc-ie-step-head"><span class="binloc-ie-step-num">'.((int) $num).'</span>'.$title.'</div>';
	print '<div class="binloc-ie-step-hint">'.$hint.'</div>';
	print '<div class="binloc-ie-step-body">'.$body.'</div>';
	print '</div>';
}

/**
 * Print the collapsible "file format and import rules" panel of a section
 *
 * @param  array<string,string> $columns  Column name => what it holds
 * @param  string[]             $rules    Import rules, one per bullet
 * @param  string[]             $examples Demo files (in demo/) that show this format
 * @param  array<string,string> $needs    Demo files to import first => where they are imported
 * @return void
 */
function binloc_ie_format($columns, $rules, $examples = array(), $needs = array())
{
	global $langs;

	print '<details class="binloc-ie-format">';
	print '<summary>'.$langs->trans('CsvFormatAndRules').'</summary>';
	print '<div class="binloc-ie-format-body">';
	print '<table class="noborder binloc-ie-columns">';
	print '<tr class="liste_titre"><td>'.$langs->trans('CsvColumn').'</td><td>'.$langs->trans('CsvColumnMeaning').'</td></tr>';
	foreach ($columns as $name => $meaning) {
		print '<tr class="oddeven"><td class="nowraponall"><code>'.$name.'</code></td><td>'.$meaning.'</td></tr>';
	}
	print '</table>';
	print '<div class="binloc-ie-rules">';
	print '<div class="binloc-ie-rules-title">'.$langs->trans('CsvRules').'</div>';
	print '<ul>';
	foreach ($rules as $rule) {
		print '<li>'.$rule.'</li>';
	}
	print '</ul>';
	if ($examples) {
		print '<div class="binloc-ie-rules-title">'.$langs->trans('CsvExampleFiles').'</div>';
		print '<ul class="binloc-ie-demo">';
		foreach ($examples as $file) {
			print '<li>'.binloc_ie_demo_link($file).'</li>';
		}
		print '</ul>';
		print '<div class="binloc-ie-demo-needs">'.$langs->trans('CsvExampleNeeds').'</div>';
		print '<ul class="binloc-ie-demo binloc-ie-demo-needs-list">';
		foreach ($needs as $file => $where) {
			print '<li>'.binloc_ie_demo_link($file).' <span class="opacitymedium">— '.$where.'</span></li>';
		}
		print '</ul>';
		print '<a href="'.dol_buildpath('/binloc/help.php', 1).'#trying-binloc-with-demo-data">'.img_picto('', 'help', 'class="pictofixedwidth"').$langs->trans('CsvDemoWalkthrough').'</a>';
	}
	print '</div>';
	print '</div>';
	print '</details>';
}

/**
 * Download link to a shipped demo file
 *
 * @param  string $file File name in the module's demo/ folder
 * @return string       HTML
 */
function binloc_ie_demo_link($file)
{
	return '<a href="'.dol_buildpath('/binloc/demo/'.$file, 1).'" download>'.img_picto('', 'download', 'class="pictofixedwidth"').dol_escape_htmltag($file).'</a>';
}

/**
 * A control with a small caption above it (the labels page's field style)
 *
 * @param  string $caption Caption text (escaped)
 * @param  string $control Control HTML
 * @return string
 */
function binloc_ie_field($caption, $control)
{
	return '<label class="binloc-field"><span class="binloc-field-caption">'.$caption.'</span>'.$control.'</label>';
}

$page_name = 'BinlocSetup';
llxHeader('', $langs->trans($page_name), binloc_help_url());
binloc_print_assets();

$head = binloc_admin_prepare_head();
print dol_get_fiche_head($head, 'importexport', $langs->trans($page_name), -1, 'stock');

print '<p class="binloc-intro">'.$langs->trans('CsvPageDesc').'</p>';

$wh_refs = array();
foreach (binloc_get_warehouses($db) as $wh) {
	$wh_refs[(int) $wh->rowid] = $wh->ref;
}
$section_titles = array('layout' => $langs->transnoentitiesnoconv('CsvLayoutSection'), 'assign' => $langs->transnoentitiesnoconv('CsvAssignSection')); // raw: trans() encodes its parameters

// Pending preview or applied result: its own card above the sections, named
// after the section (and warehouse) and file it came from
$result_kind = $preview !== null ? $preview['kind'] : $applied_kind;
if ($preview !== null || $applied_report !== null) {
	$report = $preview !== null ? $preview['report'] : $applied_report;
	$wh_id = $preview !== null ? (int) $preview['fk_entrepot'] : $fk_entrepot;
	$title = $langs->trans($preview !== null ? 'CsvPreviewOf' : 'CsvResultOf', $section_titles[$result_kind]);
	if ($result_kind === 'assign' && isset($wh_refs[$wh_id])) {
		$title .= ' · '.dol_escape_htmltag($wh_refs[$wh_id]);
	}
	$filename = $preview !== null ? $preview['filename'] : GETPOST('csvname', 'alphanohtml');
	$n_changes = binloc_ie_change_count($report);

	print '<div class="binloc-card binloc-ie-card binloc-ie-result">';
	print '<div class="binloc-ie-card-head">';
	print '<div class="binloc-ie-card-title">'.$title.'</div>';
	if ($filename !== '') {
		print '<div class="binloc-ie-card-sub">'.$langs->trans('CsvFromFile', dol_escape_htmltag($filename)).'</div>';
	}
	print '</div>';
	print '<div class="binloc-ie-result-body">';
	binloc_ie_print_report($report, $result_kind, $preview === null);

	print '<div class="binloc-ie-result-actions">';
	if ($preview !== null && empty($report['errors']) && $n_changes > 0) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="apply_'.$preview['kind'].'">';
		print '<input type="hidden" name="csvtoken" value="'.dol_escape_htmltag($preview['token']).'">';
		print '<input type="hidden" name="csvname" value="'.dol_escape_htmltag($filename).'">';
		print '<input type="hidden" name="fk_entrepot" value="'.(int) $preview['fk_entrepot'].'">';
		print '<input type="hidden" name="create_missing" value="'.(int) $preview['create_missing'].'">';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CsvConfirmImportN', $n_changes)).'">';
		print ' <a href="'.$_SERVER['PHP_SELF'].'" class="button button-cancel">'.$langs->trans('Cancel').'</a>';
		print '</form>';
	} else {
		print '<a href="'.$_SERVER['PHP_SELF'].'" class="button">'.$langs->trans($preview !== null ? 'CsvClose' : 'CsvDone').'</a>';
	}
	print '</div>';
	print '</div>';
	print '</div>';
}

$file_input = '<input type="file" name="csvfile" accept=".csv,text/csv" required>';
$no_right = '<span class="opacitymedium">%s</span>';

// ---- Layout section ----
print '<div class="binloc-card binloc-ie-card">';
print '<div class="binloc-ie-card-head">';
print '<div class="binloc-ie-card-title">'.$langs->trans('CsvLayoutSection').'</div>';
print '<div class="binloc-ie-card-sub">'.$langs->trans('CsvLayoutSub').'</div>';
print '</div>';

print '<div class="binloc-ie-steps">';
binloc_ie_step(1, $langs->trans('CsvStepExport'), $langs->trans('CsvLayoutExportHint'),
	'<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="binloc-ie-form"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="export_layout"><input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvExportAllLayouts')).'"></form>');
if ($can_admin) {
	$body = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data" class="binloc-ie-form">';
	$body .= '<input type="hidden" name="token" value="'.newToken().'">';
	$body .= '<input type="hidden" name="action" value="preview_layout">';
	$body .= binloc_ie_field($langs->trans('CsvFile'), $file_input);
	$body .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvPreviewImport')).'">';
	$body .= '</form>';
} else {
	$body = sprintf($no_right, $langs->trans('CsvNeedsAdminRight'));
}
binloc_ie_step(2, $langs->trans('CsvStepImport'), $langs->trans('CsvImportHint'), $body);
print '</div>';

binloc_ie_format(
	array(
		'warehouse'      => $langs->trans('CsvColLayoutWarehouse'),
		'level'          => $langs->trans('CsvColLayoutLevel'),
		'label'          => $langs->trans('CsvColLayoutLabel'),
		'type'           => $langs->trans('CsvColLayoutType'),
		'allowed_values' => $langs->trans('CsvColLayoutValues'),
	),
	array(
		$langs->trans('CsvRuleLayoutMatch'),
		$langs->trans('CsvRuleLayoutNoDelete'),
		$langs->trans('CsvRuleLayoutRight'),
	),
	array('4-binloc-layout.csv'),
	array('1-warehouses.csv' => $langs->trans('CsvViaDolibarrImport', $langs->transnoentitiesnoconv('Warehouses')))
);
print '</div>';

// ---- Assignments section ----
print '<div class="binloc-card binloc-ie-card">';
print '<div class="binloc-ie-card-head">';
print '<div class="binloc-ie-card-title">'.$langs->trans('CsvAssignSection').'</div>';
print '<div class="binloc-ie-card-sub">'.$langs->trans('CsvAssignSub').'</div>';
print '</div>';

// Both steps pick a warehouse; the two selects mirror each other
$wh_attrs = 'required data-binloc-wh-sync="1"';
print '<div class="binloc-ie-steps">';
$body = '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="binloc-ie-form">';
$body .= '<input type="hidden" name="token" value="'.newToken().'">';
$body .= binloc_ie_field($langs->trans('Warehouse'), binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot, 'flat minwidth200', $wh_attrs));
$body .= '<input type="hidden" name="action" value="export_assign">';
$body .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvExportAssignments')).'">';
// Second submit of the same form: its name switches the export (theme styles input.button only)
$body .= '<input type="submit" name="stock" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvExportStockBins')).'">';
$body .= '<div class="opacitymedium small">'.$langs->trans('CsvExportStockBinsHint').'</div>';
$body .= '</form>';
binloc_ie_step(1, $langs->trans('CsvStepExport'), $langs->trans('CsvAssignExportHint'), $body);
if ($can_write) {
	$body = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data" class="binloc-ie-form">';
	$body .= '<input type="hidden" name="token" value="'.newToken().'">';
	$body .= '<input type="hidden" name="action" value="preview_assign">';
	$body .= binloc_ie_field($langs->trans('Warehouse'), binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot, 'flat minwidth200', $wh_attrs));
	$body .= binloc_ie_field($langs->trans('CsvFile'), $file_input);
	$body .= '<label class="binloc-check"><input type="checkbox" name="create_missing" value="1"> '.$langs->trans('CsvCreateMissingValues').'</label>';
	$body .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CsvPreviewImport')).'">';
	$body .= '</form>';
} else {
	$body = sprintf($no_right, $langs->trans('CsvNeedsWriteRight'));
}
binloc_ie_step(2, $langs->trans('CsvStepImport'), $langs->trans('CsvImportHint'), $body);
print '</div>';

binloc_ie_format(
	array(
		'product'         => $langs->trans('CsvColAssignProduct'),
		'product_label'   => $langs->trans('CsvColAssignLabel'),
		'lot'             => $langs->trans('CsvColAssignLot'),
		'qty_in_stock'    => $langs->trans('CsvColAssignQty'),
		'bin_status'      => $langs->trans('CsvColAssignStatus'),
		'&lt;'.dol_escape_htmltag($langs->trans('CsvColAssignLevelName')).'&gt;' => $langs->trans('CsvColAssignLevel'),
		'note'            => $langs->trans('CsvColAssignNote'),
	),
	array(
		$langs->trans('CsvRuleAssignWarehouse'),
		$langs->trans('CsvRuleAssignMatch'),
		$langs->trans('CsvRuleAssignClear'),
		$langs->trans('CsvRuleAssignValues'),
		$langs->trans('CsvRuleAssignRight'),
	),
	array('5-binloc-assignments-DEMO-MAIN.csv', '6-binloc-assignments-DEMO-OVFL.csv'),
	array(
		'1-warehouses.csv'    => $langs->trans('CsvViaDolibarrImport', $langs->transnoentitiesnoconv('Warehouses')),
		'2-products.csv'      => $langs->trans('CsvViaDolibarrImport', $langs->transnoentitiesnoconv('Products')),
		'4-binloc-layout.csv' => $langs->trans('CsvViaLayoutCard'),
	)
);
print '</div>';

print '<script>';
print 'jQuery(function ($) {';
print '	var $wh = $("select[data-binloc-wh-sync]");';
print '	$wh.on("change", function () { $wh.not(this).val($(this).val()); });';
print '});';
print '</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
