<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/location_get.php
 * \ingroup binloc
 * \brief   Fetch a bin location assignment as JSON (for edit-form prefill)
 *
 * GET params: loc_id
 */

if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }
if (!defined('NOREQUIREMENU')) { define('NOREQUIREMENU', '1'); }
if (!defined('NOREQUIREHTML')) { define('NOREQUIREHTML', '1'); }
if (!defined('NOREQUIREAJAX')) { define('NOREQUIREAJAX', '1'); }

$res = 0;
if (file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force Conf $conf';
'@phan-var-force DoliDB $db';
'@phan-var-force HookManager $hookmanager';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

dol_include_once('/binloc/class/binlocproductlocation.class.php');

header('Content-Type: application/json');

if (!$user->hasRight('binloc', 'read')) {
	http_response_code(403);
	print json_encode(array('error' => 'Permission denied'));
	exit;
}

$loc_id = GETPOSTINT('loc_id');
if ($loc_id <= 0) {
	http_response_code(400);
	print json_encode(array('error' => 'Missing loc_id'));
	exit;
}

$loc = new BinlocProductLocation($db);
$result = $loc->fetch($loc_id);
if ($result <= 0 || !in_array((string) $loc->entity, explode(',', getEntity('stock')))) {
	http_response_code(404);
	print json_encode(array('error' => 'Not found'));
	exit;
}

$values = array();
foreach ($loc->values as $fk_level => $entry) {
	$values[$fk_level] = array(
		'fk_option' => $entry->fk_option,
		'value'     => $entry->value,
		'display'   => $entry->display,
	);
}

print json_encode(array(
	'success'        => true,
	'id'             => $loc->id,
	'fk_product'     => $loc->fk_product,
	'fk_entrepot'    => $loc->fk_entrepot,
	'fk_product_lot' => $loc->fk_product_lot,
	'note'           => $loc->note,
	'values'         => $values,
));

$db->close();
