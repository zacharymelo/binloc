<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    sheet.php
 * \ingroup binloc
 * \brief   One-click pick / place sheet: generate, then open the PDF
 *
 * Target of the Pick sheet / Place sheet action buttons on order, shipment
 * and reception cards (ActionsBinloc::addMoreActionsButtons). Generation goes
 * through the object's own generateDocument(), exactly like the Documents
 * block, so the file lands in the same place and stays listed there.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

dol_include_once('/binloc/lib/binloc_sheets.lib.php');

$langs->loadLangs(array('main', 'errors', 'binloc@binloc'));

$type = GETPOST('type', 'aZ09');
$id   = GETPOSTINT('id');

// Document model type => how to load the object and where its file is served from
$defs = array(
	'order' => array(
		'class'      => 'Commande',
		'include'    => array('/commande/class/commande.class.php'),
		'feature'    => 'commande',
		'card'       => '/commande/card.php',
		'modulepart' => 'commande',
		'subdir'     => '',
		'suffix'     => '-picksheet.pdf',
	),
	'shipping' => array(
		'class'      => 'Expedition',
		'include'    => array('/expedition/class/expedition.class.php'),
		'feature'    => 'expedition',
		'card'       => '/expedition/card.php',
		'modulepart' => 'expedition',
		'subdir'     => 'sending/',
		'suffix'     => '-picksheet.pdf',
	),
	'reception' => array(
		'class'      => 'Reception',
		// Reception::fetch() loads its lines through the supplier dispatch
		// classes but reception.class.php does not include them (the card
		// does) — without these the fetch is a fatal error (HTTP 500)
		'include'    => array(
			'/fourn/class/fournisseur.commande.class.php',
			'/fourn/class/fournisseur.orderline.class.php',
			'/fourn/class/fournisseur.commande.dispatch.class.php',
			'/reception/class/reception.class.php',
		),
		'feature'    => 'reception',
		'card'       => '/reception/card.php',
		'modulepart' => 'reception',
		'subdir'     => '',
		'suffix'     => '-placesheet.pdf',
	),
	'mrp' => array(
		'class'      => 'Mo',
		'include'    => array('/mrp/class/mo.class.php'),
		'feature'    => 'mrp',
		// restrictedArea() table and key: there is no llx_mrp table to check against
		'restrict'   => array('mrp_mo', '', 'fk_soc', 'rowid'),
		'card'       => '/mrp/mo_card.php',
		'modulepart' => 'mrp',
		'subdir'     => '',
		'suffix'     => '-picksheet.pdf',
	),
);

if (!isset($defs[$type]) || $id <= 0) {
	accessforbidden();
}
$def = $defs[$type];

if (GETPOST('token', 'alpha') !== currentToken()) {
	accessforbidden('Bad value for CSRF token');
}
if (!binloc_sheet_model_enabled($db, $type)) {
	accessforbidden();
}

foreach ($def['include'] as $include) {
	require_once DOL_DOCUMENT_ROOT.$include;
}
$classname = $def['class'];
$object = new $classname($db);
if ($object->fetch($id) <= 0) {
	accessforbidden();
}

// Same read access the card and its Documents block require (rights, entity, external users)
$restrict = isset($def['restrict']) ? $def['restrict'] : array('', '', 'fk_soc', 'rowid');
restrictedArea($user, $def['feature'], $object->id, $restrict[0], $restrict[1], $restrict[2], $restrict[3]);

$cardurl = DOL_URL_ROOT.$def['card'].'?id='.((int) $object->id);

// Output language: the third party's when multilang is on, as the Documents block does
$outputlangs = $langs;
if (getDolGlobalInt('MAIN_MULTILANGS') && method_exists($object, 'fetch_thirdparty')) {
	$object->fetch_thirdparty();
	if (!empty($object->thirdparty->default_lang)) {
		$outputlangs = new Translate('', $conf);
		$outputlangs->setDefaultLang($object->thirdparty->default_lang);
	}
}

$models = binloc_sheet_models();
$result = $object->generateDocument($models[$type]['name'], $outputlangs);
if ($result <= 0) {
	setEventMessages($langs->trans('SheetGenerateFailed', $object->error), $object->errors, 'errors');
	header('Location: '.$cardurl);
	exit;
}

$ref  = dol_sanitizeFileName($object->ref);
$file = $def['subdir'].$ref.'/'.$ref.$def['suffix'];

header('Location: '.DOL_URL_ROOT.'/document.php?modulepart='.urlencode($def['modulepart']).'&attachment=0&file='.urlencode($file).'&entity='.((int) $object->entity));
exit;
