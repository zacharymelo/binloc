<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/location_save.php
 * \ingroup binloc
 * \brief   Create/update a bin location assignment (AJAX, POST + CSRF token)
 *
 * POST params:
 *   token            CSRF token from newToken()
 *   fk_product       (required)
 *   fk_entrepot      (required)
 *   fk_product_lot   optional (0 = no lot)
 *   note             optional
 *   binloc_level{N}  level values (option rowid for list levels, string otherwise)
 */

if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }
if (!defined('NOREQUIREMENU')) { define('NOREQUIREMENU', '1'); }
if (!defined('NOREQUIREHTML')) { define('NOREQUIREHTML', '1'); }
if (!defined('NOREQUIREAJAX')) { define('NOREQUIREAJAX', '1'); }

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/lib/binloc_ajax.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

header('Content-Type: application/json');

binloc_ajax_require_post_with_token();

if (!$user->hasRight('binloc', 'write')) {
	http_response_code(403);
	print json_encode(array('error' => 'Permission denied'));
	exit;
}

$fk_product     = GETPOSTINT('fk_product');
$fk_entrepot    = GETPOSTINT('fk_entrepot');
$fk_product_lot = GETPOSTINT('fk_product_lot');
$note           = GETPOST('note', 'alphanohtml');

if ($fk_product <= 0 || $fk_entrepot <= 0) {
	http_response_code(400);
	print json_encode(array('error' => 'Missing product or warehouse'));
	exit;
}

// A lot must belong to the product it is being located for
if ($fk_product_lot > 0) {
	$resql = $db->query("SELECT fk_product FROM ".MAIN_DB_PREFIX."product_lot WHERE rowid = ".((int) $fk_product_lot)." AND entity IN (".getEntity('stock').")");
	$obj = $resql ? $db->fetch_object($resql) : null;
	if (!$obj || (int) $obj->fk_product !== $fk_product) {
		http_response_code(400);
		print json_encode(array('error' => 'Lot does not belong to product'));
		exit;
	}
}

$lvl = new BinlocWarehouseLevel($db);
$levels = $lvl->fetchByWarehouse($fk_entrepot);
if (empty($levels)) {
	http_response_code(400);
	print json_encode(array('error' => 'NoLevelsConfigured'));
	exit;
}

$loc = new BinlocProductLocation($db);
$loc->fk_product     = $fk_product;
$loc->fk_entrepot    = $fk_entrepot;
$loc->fk_product_lot = $fk_product_lot;
$loc->note           = $note;

if ($loc->setRawValues($levels, binloc_get_posted_level_values($levels)) < 0) {
	http_response_code(400);
	print json_encode(array('error' => $loc->error));
	exit;
}

if (!$loc->hasValues()) {
	http_response_code(400);
	print json_encode(array('error' => 'NoLocationValues'));
	exit;
}

$result = $loc->createOrUpdate($user);

if ($result > 0) {
	print json_encode(array(
		'success'   => true,
		'id'        => $loc->id,
		'formatted' => $loc->getFormattedLocation($levels),
	));
} else {
	http_response_code(500);
	print json_encode(array('error' => $loc->error ?: 'Unknown error'));
}

$db->close();
