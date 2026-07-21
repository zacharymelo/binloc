<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/location_delete.php
 * \ingroup binloc
 * \brief   Delete a bin location assignment (AJAX, POST + CSRF token)
 *
 * POST params: token, loc_id
 */

if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }
if (!defined('NOREQUIREMENU')) { define('NOREQUIREMENU', '1'); }
if (!defined('NOREQUIREHTML')) { define('NOREQUIREHTML', '1'); }
if (!defined('NOREQUIREAJAX')) { define('NOREQUIREAJAX', '1'); }

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

dol_include_once('/binloc/lib/binloc_ajax.lib.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

header('Content-Type: application/json');

binloc_ajax_require_post_with_token();

if (!$user->hasRight('binloc', 'write')) {
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

if ($loc->delete($user) > 0) {
	print json_encode(array('success' => true));
} else {
	http_response_code(500);
	print json_encode(array('error' => $loc->error ?: 'Unknown error'));
}

$db->close();
