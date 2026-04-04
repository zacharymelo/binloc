<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    ajax/debug.php
 * \ingroup wareloc
 * \brief   Comprehensive debug diagnostics for the wareloc module.
 *          Gated by admin permission + WARELOC_DEBUG_MODE setting.
 *
 * Modes (via ?mode=):
 *   overview    — Module config, hook contexts, trigger registration, DB table health (default)
 *   object      — Deep inspect a single object (?mode=object&type=productlocation&id=11)
 *   links       — All element_element rows involving this module's types
 *   settings    — All WARELOC_* constants from llx_const
 *   classes     — Class loading + method availability for all module objects
 *   sql         — Run a read-only diagnostic query (?mode=sql&q=SELECT...)
 *   triggers    — List all registered triggers and check ours is loaded
 *   hooks       — Show registered hook contexts and verify our hooks fire
 *   all         — Run every diagnostic at once
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { http_response_code(500); exit; }

if (!$user->admin) { http_response_code(403); print 'Admin only'; exit; }
if (!getDolGlobalInt('WARELOC_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Go to Wareloc > Setup and enable Debug Mode.';
	exit;
}

header('Content-Type: text/plain; charset=utf-8');

$mode = GETPOST('mode', 'alpha') ?: 'overview';
$run_all = ($mode === 'all');

$MODULE_NAME   = 'wareloc';
$MODULE_UPPER  = 'WARELOC';
$OBJECTS = array(
	'productlocation' => array(
		'class'      => 'ProductLocation',
		'classfile'  => 'productlocation',
		'table'      => 'wareloc_product_location',
		'prefixed'   => 'wareloc_productlocation',
		'fk_fields'  => array('fk_product', 'fk_entrepot', 'fk_reception'),
	),
);

print "=== WARELOC DEBUG DIAGNOSTICS ===\n";
print "Timestamp: ".date('Y-m-d H:i:s T')."\n";
print "Dolibarr: ".(defined('DOL_VERSION') ? DOL_VERSION : 'unknown')."\n";
print "Module version: ".getDolGlobalString('MAIN_MODULE_WARELOC_VERSION', 'unknown')."\n";
print "Mode: $mode\n";
print "Usage: ?mode=overview|object|links|settings|classes|sql|triggers|hooks|all\n";
print "       ?mode=object&type=productlocation&id=11\n";
print "       ?mode=sql&q=SELECT+rowid,ref+FROM+llx_wareloc_product_location+LIMIT+5\n";
print str_repeat('=', 60)."\n\n";


// =====================================================================
// OVERVIEW
// =====================================================================
if ($mode === 'overview' || $run_all) {
	print "--- MODULE STATUS ---\n";
	print "isModEnabled('$MODULE_NAME'): ".(isModEnabled($MODULE_NAME) ? 'YES' : 'NO')."\n";
	print "conf->$MODULE_NAME->enabled: ".(isset($conf->$MODULE_NAME->enabled) ? $conf->$MODULE_NAME->enabled : '(not set)')."\n";

	print "\nRegistered module_parts:\n";
	if (isset($conf->modules_parts)) {
		foreach (array('hooks', 'triggers', 'tpl') as $part) {
			if (isset($conf->modules_parts[$part])) {
				$found = false;
				foreach ($conf->modules_parts[$part] as $k => $v) {
					if (stripos($k, $MODULE_NAME) !== false || stripos(print_r($v, true), $MODULE_NAME) !== false) {
						print "  $part: ".print_r($v, true);
						$found = true;
					}
				}
				if (!$found) print "  $part: ($MODULE_NAME not found in registered parts)\n";
			} else {
				print "  $part: (not in modules_parts)\n";
			}
		}
	}

	print "\n--- DATABASE TABLES ---\n";
	$tables = array('wareloc_level', 'wareloc_product_location', 'wareloc_product_location_extrafields', 'wareloc_product_default');
	foreach ($tables as $tbl) {
		$sql = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX.$tbl;
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			print "  llx_$tbl: ".$obj->cnt." rows\n";
		} else {
			print "  llx_$tbl: TABLE MISSING OR ERROR\n";
		}
	}

	print "\n--- ELEMENT PROPERTIES ---\n";
	foreach ($OBJECTS as $bare => $odef) {
		foreach (array($bare, $odef['prefixed']) as $etype) {
			$props = getElementProperties($etype);
			$ok = (!empty($props['classname']) && $props['classname'] === $odef['class']);
			$cn = isset($props['classname']) ? $props['classname'] : '(empty)';
			print "  $etype → classname=$cn ".($ok ? 'OK' : 'MISMATCH (expected '.$odef['class'].')')."\n";
		}
	}

	print "\n--- LINKED OBJECT TEMPLATES ---\n";
	foreach ($OBJECTS as $bare => $odef) {
		$tplpath = $MODULE_NAME.'/'.$bare.'/tpl/linkedobjectblock.tpl.php';
		$fullpath = dol_buildpath('/'.$tplpath);
		print "  $tplpath: ".(file_exists($fullpath) ? 'EXISTS' : 'MISSING ('.$fullpath.')')."\n";
	}

	print "\n--- HIERARCHY LEVELS ---\n";
	dol_include_once('/wareloc/lib/wareloc.lib.php');
	$levels = wareloc_get_active_levels();
	if (empty($levels)) {
		print "  (none configured)\n";
	} else {
		foreach ($levels as $lev) {
			print "  Position ".$lev->position.": ".$lev->label." (".$lev->datatype.")";
			if ($lev->datatype === 'list') {
				print " values=[".$lev->list_values."]";
			}
			print $lev->required ? " REQUIRED" : "";
			print "\n";
		}
	}

	print "\n";
}


// =====================================================================
// OBJECT
// =====================================================================
if ($mode === 'object' || $run_all) {
	$otype = GETPOST('type', 'alpha') ?: 'productlocation';
	$oid   = GETPOSTINT('id');

	if ($oid <= 0 && !$run_all) {
		print "--- OBJECT DIAGNOSIS ---\nUsage: ?mode=object&type=productlocation&id=11\n\n";
	} elseif ($oid > 0) {
		$odef = isset($OBJECTS[$otype]) ? $OBJECTS[$otype] : null;
		if (!$odef) {
			print "--- OBJECT DIAGNOSIS ---\nUnknown type '$otype'. Available: ".implode(', ', array_keys($OBJECTS))."\n\n";
		} else {
			print "--- OBJECT DIAGNOSIS: $otype id=$oid ---\n";
			dol_include_once('/'.$MODULE_NAME.'/class/'.$odef['classfile'].'.class.php');
			$classname = $odef['class'];

			if (!class_exists($classname)) {
				print "  Class $classname NOT FOUND after include!\n\n";
			} else {
				$obj = new $classname($db);
				$fetch_result = $obj->fetch($oid);
				print "  fetch() returned: $fetch_result\n";

				if ($fetch_result > 0) {
					print "  ref: $obj->ref\n";
					print "  element: $obj->element\n";
					print "  module: ".(property_exists($obj, 'module') ? ($obj->module ?: '(empty)') : '(NOT DEFINED)')."\n";
					print "  getElementType(): ".$obj->getElementType()."\n";
					print "  getNomUrl(): ".(method_exists($obj, 'getNomUrl') ? 'defined' : 'MISSING')."\n";
					print "  getLibStatut(): ".(method_exists($obj, 'getLibStatut') ? 'defined' : 'MISSING')."\n";

					print "\n  FK fields (non-empty):\n";
					$has_fk = false;
					foreach ($odef['fk_fields'] as $fk) {
						$val = isset($obj->$fk) ? $obj->$fk : null;
						if (!empty($val)) {
							print "    $fk = $val\n";
							$has_fk = true;
						}
					}
					if (!$has_fk) print "    (none populated)\n";

					print "\n  Status: ".$obj->status."\n";
					print "  Location: ".$obj->getLocationLabel()."\n";

					// element_element
					$etype = $obj->getElementType();
					print "\n  element_element rows:\n";

					$search_types = array($etype);
					if ($etype !== $obj->element) {
						$search_types[] = $obj->element;
					}
					$where_parts = array();
					foreach ($search_types as $st) {
						$where_parts[] = "(fk_source = $oid AND sourcetype = '".$db->escape($st)."')";
						$where_parts[] = "(fk_target = $oid AND targettype = '".$db->escape($st)."')";
					}

					$sql = "SELECT DISTINCT rowid, fk_source, sourcetype, fk_target, targettype FROM ".MAIN_DB_PREFIX."element_element WHERE ".implode(" OR ", $where_parts)." ORDER BY rowid";
					$resql = $db->query($sql);
					if ($resql) {
						$cnt = 0;
						while ($row = $db->fetch_object($resql)) {
							$cnt++;
							print "    [$row->rowid] source=$row->fk_source ($row->sourcetype) → target=$row->fk_target ($row->targettype)\n";
						}
						if ($cnt == 0) print "    (none found)\n";
					}
				}
			}
			print "\n";
		}
	}
}


// =====================================================================
// LINKS
// =====================================================================
if ($mode === 'links' || $run_all) {
	print "--- ALL ELEMENT_ELEMENT ROWS FOR $MODULE_UPPER ---\n";

	$type_patterns = array();
	foreach ($OBJECTS as $bare => $odef) {
		$type_patterns[] = "sourcetype LIKE '%".$db->escape($bare)."%'";
		$type_patterns[] = "targettype LIKE '%".$db->escape($bare)."%'";
	}

	$sql = "SELECT rowid, fk_source, sourcetype, fk_target, targettype FROM ".MAIN_DB_PREFIX."element_element WHERE ".implode(" OR ", $type_patterns)." ORDER BY rowid DESC LIMIT 50";
	$resql = $db->query($sql);
	if ($resql) {
		$cnt = 0;
		while ($row = $db->fetch_object($resql)) {
			$cnt++;
			print "  [$row->rowid] source=$row->fk_source ($row->sourcetype) → target=$row->fk_target ($row->targettype)\n";
		}
		print "  Total: $cnt rows (max 50 shown)\n";
	}
	print "\n";
}


// =====================================================================
// SETTINGS
// =====================================================================
if ($mode === 'settings' || $run_all) {
	print "--- WARELOC SETTINGS ---\n";

	$sql = "SELECT name, value, note FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'WARELOC%' AND entity IN (0, ".((int) $conf->entity).") ORDER BY name";
	$resql = $db->query($sql);
	if ($resql) {
		while ($row = $db->fetch_object($resql)) {
			$display_val = strlen($row->value) > 80 ? substr($row->value, 0, 80).'...' : $row->value;
			print "  $row->name = $display_val\n";
		}
	}
	print "\n";
}


// =====================================================================
// CLASSES
// =====================================================================
if ($mode === 'classes' || $run_all) {
	print "--- CLASS LOADING & METHODS ---\n";

	foreach ($OBJECTS as $bare => $odef) {
		print "  $bare ({$odef['class']}):\n";
		$inc = @dol_include_once('/'.$MODULE_NAME.'/class/'.$odef['classfile'].'.class.php');
		print "    dol_include_once: ".($inc ? 'OK' : 'FAILED')."\n";
		print "    class_exists: ".(class_exists($odef['class']) ? 'YES' : 'NO')."\n";

		if (class_exists($odef['class'])) {
			$required_methods = array('create', 'fetch', 'update', 'delete', 'validate', 'getNomUrl', 'getLibStatut', 'getNextNumRef');
			$obj = new $odef['class']($db);
			print "    \$module property: ".(property_exists($obj, 'module') ? ($obj->module ?: '(empty)') : 'NOT DEFINED')."\n";
			print "    \$element: ".$obj->element."\n";
			print "    getElementType(): ".$obj->getElementType()."\n";

			$methods_ok = true;
			$missing = array();
			foreach ($required_methods as $m) {
				if (!method_exists($obj, $m)) {
					$methods_ok = false;
					$missing[] = $m;
				}
			}
			print "    Required methods: ".($methods_ok ? 'ALL PRESENT' : 'MISSING: '.implode(', ', $missing))."\n";
		}
		print "\n";
	}
}


// =====================================================================
// SQL
// =====================================================================
if ($mode === 'sql') {
	$q = GETPOST('q', 'restricthtml');
	print "--- SQL QUERY ---\n";

	if (empty($q)) {
		print "Usage: ?mode=sql&q=SELECT+rowid,ref+FROM+llx_wareloc_product_location+LIMIT+5\n";
		print "\nUseful queries:\n";
		print "  ?mode=sql&q=SELECT rowid,ref,status FROM llx_wareloc_product_location ORDER BY rowid DESC LIMIT 10\n";
		print "  ?mode=sql&q=SELECT rowid,position,code,label,datatype FROM llx_wareloc_level WHERE active=1 ORDER BY position\n";
		print "  ?mode=sql&q=SELECT rowid,fk_product,fk_entrepot,level_1,level_2,level_3,level_4 FROM llx_wareloc_product_default ORDER BY rowid DESC LIMIT 10\n";
		print "  ?mode=sql&q=SELECT rowid,fk_source,sourcetype,fk_target,targettype FROM llx_element_element WHERE sourcetype LIKE '%wareloc%' OR targettype LIKE '%wareloc%' ORDER BY rowid DESC LIMIT 20\n";
	} else {
		$q_trimmed = trim($q);
		if (stripos($q_trimmed, 'SELECT') !== 0) {
			print "ERROR: Only SELECT queries allowed.\n";
		} else {
			$blocked = array('INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'GRANT', 'REVOKE');
			$safe = true;
			foreach ($blocked as $kw) {
				if (stripos($q_trimmed, $kw) !== false && stripos($q_trimmed, $kw) !== stripos($q_trimmed, 'SELECT')) {
					$safe = false;
					break;
				}
			}
			if (!$safe) {
				print "ERROR: Query contains blocked keywords.\n";
			} else {
				if (stripos($q_trimmed, 'LIMIT') === false) {
					$q_trimmed .= ' LIMIT 50';
				}

				print "Query: $q_trimmed\n\n";
				$resql = $db->query($q_trimmed);
				if ($resql) {
					$first = true;
					$row_num = 0;
					while ($obj = $db->fetch_array($resql)) {
						if ($first) {
							print implode("\t", array_keys($obj))."\n";
							print str_repeat('-', 80)."\n";
							$first = false;
						}
						$row_num++;
						$vals = array();
						foreach ($obj as $v) {
							$vals[] = ($v === null) ? 'NULL' : (strlen($v) > 40 ? substr($v, 0, 40).'...' : $v);
						}
						print implode("\t", $vals)."\n";
					}
					print "\n$row_num rows returned.\n";
				} else {
					print "SQL ERROR: ".$db->lasterror()."\n";
				}
			}
		}
	}
	print "\n";
}


// =====================================================================
// TRIGGERS
// =====================================================================
if ($mode === 'triggers' || $run_all) {
	print "--- TRIGGER REGISTRATION ---\n";

	$trigger_dir = DOL_DOCUMENT_ROOT.'/custom/'.$MODULE_NAME.'/core/triggers';
	if (is_dir($trigger_dir)) {
		$files = scandir($trigger_dir);
		foreach ($files as $f) {
			if (preg_match('/^interface_.*\.class\.php$/', $f)) {
				print "  Found trigger file: $f\n";
				include_once $trigger_dir.'/'.$f;
				$classname = str_replace('.class.php', '', $f);
				print "    Class exists: ".(class_exists($classname) ? 'YES' : 'NO')."\n";
			}
		}
	} else {
		print "  Trigger directory not found: $trigger_dir\n";
	}

	$trigger_file = $trigger_dir.'/interface_99_modWareloc_WarelocTrigger.class.php';
	if (file_exists($trigger_file)) {
		$content = file_get_contents($trigger_file);
		preg_match_all("/case\s+'([^']+)'/", $content, $matches);
		if (!empty($matches[1])) {
			print "\n  Events handled:\n";
			foreach ($matches[1] as $event) {
				print "    - $event\n";
			}
		}
	}
	print "\n";
}


// =====================================================================
// HOOKS
// =====================================================================
if ($mode === 'hooks' || $run_all) {
	print "--- HOOK REGISTRATION ---\n";

	$sql_hooks = "SELECT name, value FROM ".MAIN_DB_PREFIX."const WHERE name = 'MAIN_MODULE_WARELOC_HOOKS' AND entity IN (0, ".((int) $conf->entity).")";
	$resql = $db->query($sql_hooks);
	if ($resql && ($row = $db->fetch_object($resql))) {
		print "  MAIN_MODULE_WARELOC_HOOKS = $row->value\n";
	}

	print "\n  Hook contexts from conf->modules_parts['hooks']:\n";
	if (isset($conf->modules_parts['hooks'])) {
		foreach ($conf->modules_parts['hooks'] as $context => $modules) {
			if (is_array($modules)) {
				foreach ($modules as $mod) {
					if (stripos($mod, $MODULE_NAME) !== false) {
						print "    context='$context' module='$mod'\n";
					}
				}
			} elseif (stripos($modules, $MODULE_NAME) !== false) {
				print "    context='$context' module='$modules'\n";
			}
		}
	}

	print "\n  Actions class:\n";
	$actions_file = DOL_DOCUMENT_ROOT.'/custom/'.$MODULE_NAME.'/class/actions_'.$MODULE_NAME.'.class.php';
	print "    File exists: ".(file_exists($actions_file) ? 'YES' : 'NO')."\n";
	if (file_exists($actions_file)) {
		include_once $actions_file;
		$actions_class = 'ActionsWareloc';
		print "    Class exists: ".(class_exists($actions_class) ? 'YES' : 'NO')."\n";
		if (class_exists($actions_class)) {
			$methods = array('getElementProperties', 'formObjectOptions', 'showLinkToObjectBlock', 'doActions');
			foreach ($methods as $m) {
				print "    method $m(): ".(method_exists($actions_class, $m) ? 'defined' : 'MISSING')."\n";
			}
		}
	}
	print "\n";
}


print "=== END DEBUG ===\n";
