<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    help.php
 * \ingroup binloc
 * \brief   In-app user guide — renders docs/USER-GUIDE.md
 *
 * Every Binloc page links here via the standard Dolibarr help icon
 * (llxHeader's help_url). The guide ships with the module so the docs
 * always match the installed version.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/parsemd.lib.php';
dol_include_once('/binloc/lib/binloc.lib.php');

$langs->loadLangs(array('binloc@binloc'));

if (!$user->hasRight('binloc', 'read') && !$user->admin) {
	accessforbidden();
}

$guide_path = dol_buildpath('/binloc/docs/USER-GUIDE.md', 0);
$content = @file_get_contents($guide_path);

// ---- VIEW ----

llxHeader('', $langs->trans('BinlocHelp'), '');

print load_fiche_titre($langs->trans('BinlocHelp'), '', 'help');

print '<div class="fichecenter binloc-help">';

if ($content === false) {
	print '<div class="error">'.$langs->trans('BinlocHelpMissing').'</div>';
} else {
	$html = dolMd2Html($content);

	// Parsedown emits headings without ids; add GitHub-style slug ids so the
	// guide's own table-of-contents anchors keep working (punctuation is
	// dropped, spaces become hyphens, doubles are NOT collapsed).
	$html = preg_replace_callback('/<h([1-6])>(.*?)<\/h\1>/s', function ($m) {
		$slug = strtolower(trim(strip_tags($m[2])));
		$slug = preg_replace('/[^a-z0-9\- ]/', '', $slug);
		$slug = str_replace(' ', '-', $slug);
		return '<h'.$m[1].' id="'.$slug.'">'.$m[2].'</h'.$m[1].'>';
	}, $html);

	print $html;
}

print '</div>';

llxFooter();
$db->close();
