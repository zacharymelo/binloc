<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/batch_save.php
 * \ingroup binloc
 * \brief   Save many bin location assignments in one call (bulk tables)
 *
 * POST body: JSON {token, rows: [{key, fk_product, fk_entrepot, fk_product_lot,
 *   note, levels: {level_rowid: raw}, clear: bool}]}
 *
 * Semantics per row:
 *   - clear=true          delete the existing assignment (explicit action)
 *   - otherwise           merge: posted non-empty level values overwrite,
 *                         empty posted fields KEEP the stored value — a blank
 *                         input never wipes data (only-fill-non-empty)
 *
 * Response: {saved, deleted, skipped, errors: [{key, error}]}
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
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binlocproductlocation.class.php');

header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true);

binloc_ajax_require_post_with_token(isset($payload['token']) ? $payload['token'] : '');

if (!$user->hasRight('binloc', 'write')) {
	http_response_code(403);
	print json_encode(array('error' => 'Permission denied'));
	exit;
}

if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
	http_response_code(400);
	print json_encode(array('error' => 'Invalid payload'));
	exit;
}

$lvl = new BinlocWarehouseLevel($db);
$levels_cache = array();

$saved = 0;
$deleted = 0;
$skipped = 0;
$errors = array();

foreach ($payload['rows'] as $i => $row) {
	$key            = isset($row['key']) ? (string) $row['key'] : (string) $i;
	$fk_product     = isset($row['fk_product']) ? (int) $row['fk_product'] : 0;
	$fk_entrepot    = isset($row['fk_entrepot']) ? (int) $row['fk_entrepot'] : 0;
	$fk_product_lot = isset($row['fk_product_lot']) ? (int) $row['fk_product_lot'] : 0;
	$clear          = !empty($row['clear']);
	$raw_levels     = (isset($row['levels']) && is_array($row['levels'])) ? $row['levels'] : array();

	if ($fk_product <= 0 || $fk_entrepot <= 0) {
		$errors[] = array('key' => $key, 'error' => 'Missing product or warehouse');
		continue;
	}

	$loc = new BinlocProductLocation($db);

	if ($clear) {
		$existing = ($fk_product_lot > 0)
			? $loc->findRowIdByLot($fk_product_lot)
			: $loc->findRowId($fk_product, $fk_entrepot, 0);
		if ($existing > 0) {
			$loc->id = $existing;
			if ($loc->delete($user) > 0) {
				$deleted++;
			} else {
				$errors[] = array('key' => $key, 'error' => $loc->error ?: 'Delete failed');
			}
		} else {
			$skipped++;
		}
		continue;
	}

	if (!isset($levels_cache[$fk_entrepot])) {
		$levels_cache[$fk_entrepot] = $lvl->fetchByWarehouse($fk_entrepot);
	}
	$levels = $levels_cache[$fk_entrepot];
	if (empty($levels)) {
		$errors[] = array('key' => $key, 'error' => 'NoLevelsConfigured');
		continue;
	}

	// Start from stored values so empty inputs keep existing data
	$existing = ($fk_product_lot > 0)
		? $loc->findRowIdByLot($fk_product_lot)
		: $loc->findRowId($fk_product, $fk_entrepot, 0);
	if ($existing > 0) {
		$loc->fetch($existing);
	}

	$loc->fk_product     = $fk_product;
	$loc->fk_entrepot    = $fk_entrepot;
	$loc->fk_product_lot = $fk_product_lot;
	if (array_key_exists('note', $row) && $row['note'] !== '') {
		$loc->note = dol_string_nohtmltag((string) $row['note']);
	}

	$changed = false;
	$failed = false;
	foreach ($levels as $id => $cfg) {
		$raw = isset($raw_levels[$id]) ? $raw_levels[$id] : '';
		if ($raw === '' || $raw === null) {
			continue; // only-fill-non-empty: blanks never overwrite
		}
		if ($loc->setRawValue($cfg, dol_string_nohtmltag((string) $raw)) < 0) {
			$errors[] = array('key' => $key, 'error' => $loc->error);
			$failed = true;
			break;
		}
		$changed = true;
	}
	if ($failed) {
		continue;
	}

	if (!$changed && $existing <= 0) {
		$skipped++;
		continue;
	}
	if (!$loc->hasValues()) {
		$skipped++;
		continue;
	}

	if ($loc->createOrUpdate($user) > 0) {
		$saved++;
	} else {
		$errors[] = array('key' => $key, 'error' => $loc->error ?: 'Save failed');
	}
}

print json_encode(array(
	'success' => empty($errors),
	'saved'   => $saved,
	'deleted' => $deleted,
	'skipped' => $skipped,
	'errors'  => $errors,
));

$db->close();
