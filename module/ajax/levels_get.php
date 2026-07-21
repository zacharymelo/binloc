<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/levels_get.php
 * \ingroup binloc
 * \brief   Return the level input fragment + level metadata for a warehouse
 *
 * GET params:
 *   fk_entrepot (required)
 *   prefix      input name prefix (e.g. "row12_" or the literal "__PREFIX__"
 *               placeholder for client-side cloning in bulk tables)
 *   loc_id      optional assignment rowid to prefill current values
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
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

header('Content-Type: application/json');

if (!$user->hasRight('binloc', 'read')) {
	http_response_code(403);
	print json_encode(array('error' => 'Permission denied'));
	exit;
}

$fk_entrepot = GETPOSTINT('fk_entrepot');
$prefix      = GETPOST('prefix', 'alphanohtml');
$loc_id      = GETPOSTINT('loc_id');

if ($fk_entrepot <= 0) {
	http_response_code(400);
	print json_encode(array('error' => 'Missing warehouse'));
	exit;
}

// Prefix is embedded in input names; restrict to a safe charset
if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix)) {
	http_response_code(400);
	print json_encode(array('error' => 'Invalid prefix'));
	exit;
}

$lvl = new BinlocWarehouseLevel($db);
$levels = $lvl->fetchByWarehouse($fk_entrepot);

$values = array();
if ($loc_id > 0) {
	$loc = new BinlocProductLocation($db);
	if ($loc->fetch($loc_id) > 0 && in_array((string) $loc->entity, explode(',', getEntity('stock')))) {
		$values = $loc->values;
	}
}

$meta = array();
foreach ($levels as $id => $cfg) {
	$meta[] = array(
		'id'       => $id,
		'label'    => $cfg->label,
		'datatype' => $cfg->datatype,
		'position' => $cfg->position,
	);
}

print json_encode(array(
	'success' => true,
	'html'    => binloc_render_level_inputs($levels, $prefix, $values),
	'levels'  => $meta,
));

$db->close();
