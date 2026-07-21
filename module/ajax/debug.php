<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/debug.php
 * \ingroup binloc
 * \brief   Fixed read-only diagnostics for the Binloc module.
 *          Gated by admin permission + BINLOC_DEBUG_MODE setting.
 *
 * v2: the arbitrary-SELECT mode was removed — a keyword blocklist is not a
 * security boundary. Diagnostics are fixed queries only: table counts,
 * migration status/report, referential integrity checks, level/option config.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { http_response_code(500); exit; }

if (!$user->admin) { http_response_code(403); print 'Admin only'; exit; }
if (!getDolGlobalInt('BINLOC_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Enable it on the Binloc setup page.';
	exit;
}

dol_include_once('/binloc/class/binlocmigration.class.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');

header('Content-Type: text/plain; charset=utf-8');

/**
 * Print a single-value diagnostic query result
 *
 * @param DoliDB $db    Database handler
 * @param string $label Line label
 * @param string $sql   Fixed read-only query returning one value
 * @return void
 */
function binloc_debug_scalar($db, $label, $sql)
{
	$resql = $db->query($sql);
	if (!$resql) {
		print sprintf("  %-46s ERROR: %s\n", $label, $db->lasterror());
		return;
	}
	$row = $db->fetch_row($resql);
	$db->free($resql);
	print sprintf("  %-46s %s\n", $label, $row ? $row[0] : '0');
}

print "==== Binloc diagnostics ====\n\n";

// ---- Module / migration status ----
print "[module]\n";
print sprintf("  %-46s %s\n", 'module enabled', isModEnabled('binloc') ? 'yes' : 'NO');
$migration = new BinlocMigration($db);
$status = $migration->getStatus();
print sprintf("  %-46s %s\n", 'db version', $status->version ?: '(none)');
print sprintf("  %-46s %s\n", 'target version', $status->target);
print sprintf("  %-46s %s\n", 'migration state', $status->state);
if ($status->error) {
	print sprintf("  %-46s %s\n", 'migration error', $status->error);
}
if (!empty($status->report)) {
	print "  migration report:\n";
	foreach ($status->report as $k => $v) {
		print sprintf("    %-44s %s\n", $k, is_array($v) ? json_encode($v) : $v);
	}
}
print "\n";

// ---- Table counts ----
print "[tables]\n";
$tables = array('binloc_product_location', 'binloc_warehouse_levels', 'binloc_level_options', 'binloc_location_value');
foreach ($tables as $t) {
	binloc_debug_scalar($db, MAIN_DB_PREFIX.$t, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX.$t);
}
print "\n";

// ---- Referential integrity ----
print "[integrity] (all should be 0)\n";
$pl = MAIN_DB_PREFIX.'binloc_product_location';
$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';
$lo = MAIN_DB_PREFIX.'binloc_level_options';
$lv = MAIN_DB_PREFIX.'binloc_location_value';
binloc_debug_scalar($db, 'locations without product', "SELECT COUNT(*) FROM ".$pl." pl LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product WHERE p.rowid IS NULL");
binloc_debug_scalar($db, 'locations without warehouse', "SELECT COUNT(*) FROM ".$pl." pl LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = pl.fk_entrepot WHERE e.rowid IS NULL");
binloc_debug_scalar($db, 'lot locations without lot', "SELECT COUNT(*) FROM ".$pl." pl LEFT JOIN ".MAIN_DB_PREFIX."product_lot l ON l.rowid = pl.fk_product_lot WHERE pl.fk_product_lot > 0 AND l.rowid IS NULL");
binloc_debug_scalar($db, 'values without location', "SELECT COUNT(*) FROM ".$lv." v LEFT JOIN ".$pl." p ON p.rowid = v.fk_location WHERE p.rowid IS NULL");
binloc_debug_scalar($db, 'values without level', "SELECT COUNT(*) FROM ".$lv." v LEFT JOIN ".$wl." w ON w.rowid = v.fk_level WHERE w.rowid IS NULL");
binloc_debug_scalar($db, 'values without option', "SELECT COUNT(*) FROM ".$lv." v LEFT JOIN ".$lo." o ON o.rowid = v.fk_option WHERE v.fk_option IS NOT NULL AND o.rowid IS NULL");
binloc_debug_scalar($db, 'values with both/neither option+value', "SELECT COUNT(*) FROM ".$lv." WHERE (fk_option IS NULL AND (value IS NULL OR value = '')) OR (fk_option IS NOT NULL AND value IS NOT NULL AND value != '')");
binloc_debug_scalar($db, 'options without level', "SELECT COUNT(*) FROM ".$lo." o LEFT JOIN ".$wl." w ON w.rowid = o.fk_level WHERE w.rowid IS NULL");
binloc_debug_scalar($db, 'duplicate (product,warehouse,lot) headers', "SELECT COUNT(*) - COUNT(DISTINCT entity, fk_product, fk_entrepot, fk_product_lot) FROM ".$pl);
print "\n";

// ---- Settings ----
print "[settings]\n";
$resql = $db->query("SELECT name, value FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'BINLOC_%' ORDER BY name");
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		print sprintf("  %-46s %s\n", $obj->name, $obj->value);
	}
	$db->free($resql);
}
print "\n";

// ---- Level configuration per warehouse ----
print "[levels]\n";
$levelObj = new BinlocWarehouseLevel($db);
$resql = $db->query("SELECT DISTINCT w.fk_entrepot, e.ref FROM ".$wl." w LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = w.fk_entrepot ORDER BY e.ref");
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		print "  ".($obj->ref ?: ('#'.$obj->fk_entrepot))."\n";
		foreach ($levelObj->fetchByWarehouse($obj->fk_entrepot, true) as $id => $cfg) {
			$opts = array();
			foreach ($cfg->options as $o) {
				$opts[] = $o->value.($o->active ? '' : ' (inactive)');
			}
			print sprintf(
				"    [%d] pos %d  %-20s %-7s%s%s\n",
				$id,
				$cfg->position,
				$cfg->label,
				$cfg->datatype,
				($cfg->active ? '' : '  INACTIVE'),
				($opts ? '  {'.implode(', ', $opts).'}' : '')
			);
		}
	}
	$db->free($resql);
}

print "\n==== end ====\n";
$db->close();
