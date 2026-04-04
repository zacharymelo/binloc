<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    warehouse_tree.php
 * \ingroup wareloc
 * \brief   Warehouse hierarchy tree builder — view, manage, and quick-build bin trees
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/wareloc/lib/wareloc.lib.php');

$langs->loadLangs(array('stocks', 'wareloc@wareloc'));

if (!$user->admin) {
	accessforbidden();
}

$action  = GETPOST('action', 'aZ09');
$fk_root = GETPOSTINT('fk_root');
$fk_node = GETPOSTINT('fk_node');

// ---- ACTIONS ----

// Quick-build: auto-generate the full tree under a root warehouse
if ($action === 'quickbuild' && $fk_root > 0) {
	$depth_labels = wareloc_get_depth_labels($db, $fk_root);
	if (empty($depth_labels)) {
		setEventMessages($langs->trans('NoLevelNamesDefined'), null, 'warnings');
	} else {
		$counts = GETPOST('counts', 'array');
		if (!is_array($counts) || empty($counts)) {
			setEventMessages($langs->trans('QuickBuildCountsRequired'), null, 'errors');
		} else {
			$root_obj = new Entrepot($db);
			if ($root_obj->fetch($fk_root) <= 0) {
				setEventMessages($langs->trans('WarehouseNotFound'), null, 'errors');
			} else {
				$db->begin();
				$created = 0;
				$error   = wareloc_quickbuild_level($db, $conf, $user, $fk_root, $root_obj->label, $depth_labels, $counts, 1, $created);
				if ($error) {
					$db->rollback();
					setEventMessages($langs->trans('QuickBuildFailed'), null, 'errors');
				} else {
					$db->commit();
					setEventMessages($langs->trans('QuickBuildDone', $created), null, 'mesgs');
				}
			}
		}
	}
	$action = '';
}

// Bulk-add children under a specific node (auto-named, continuing index from last sibling)
if ($action === 'bulkaddchildren' && $fk_node > 0 && $fk_root > 0) {
	$bulk_count = max(1, min(999, GETPOSTINT('bulk_count')));

	$sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $fk_node)." AND entity IN (".getEntity('stock').")";
	$resql = $db->query($sql);
	$parent_obj = $resql ? $db->fetch_object($resql) : null;

	if (!$parent_obj) {
		setEventMessages($langs->trans('WarehouseNotFound'), null, 'errors');
	} else {
		// Count existing children to continue numbering
		$existing = wareloc_get_children($fk_node, $db);
		$start    = count($existing) + 1;

		// Determine child depth label
		$node_depth   = wareloc_get_node_depth($fk_node, $fk_root, $db);
		$child_depth  = $node_depth + 1;
		$depth_labels = wareloc_get_depth_labels($db, $fk_root);
		$child_label  = isset($depth_labels[$child_depth]) ? $depth_labels[$child_depth] : ('L'.$child_depth);

		$db->begin();
		$error   = 0;
		$created = 0;

		for ($i = $start; $i < $start + $bulk_count; $i++) {
			$seg = wareloc_make_ref_segment($child_label, $i, 2);
			$ref = $parent_obj->ref.'-'.$seg;

			$w = new Entrepot($db);
			$w->label     = $ref;
			$w->fk_parent = $fk_node;
			$w->statut    = 1;

			if ($w->create($user) <= 0) {
				$error++;
				break;
			}
			$created++;
		}

		if ($error) {
			$db->rollback();
			setEventMessages($langs->trans('BulkAddFailed'), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('BulkAddDone', $created), null, 'mesgs');
		}
	}
	$action = '';
}

// Rename a warehouse node
if ($action === 'renamenode' && $fk_node > 0) {
	$new_ref = trim(GETPOST('new_ref', 'alpha'));
	if (empty($new_ref)) {
		setEventMessages($langs->trans('RefRequired'), null, 'errors');
	} else {
		$w = new Entrepot($db);
		if ($w->fetch($fk_node) > 0) {
			$w->label = $new_ref;
			if ($w->update($fk_node, $user) > 0) {
				setEventMessages($langs->trans('NodeRenamed'), null, 'mesgs');
			} else {
				setEventMessages($w->error ?: $db->lasterror(), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('WarehouseNotFound'), null, 'errors');
		}
	}
	$action = '';
}

// Add a single manually-named child
if ($action === 'addchild' && $fk_node > 0) {
	$child_ref  = GETPOST('child_ref', 'alpha');
	$child_desc = GETPOST('child_desc', 'alphanohtml');
	if (empty($child_ref)) {
		setEventMessages($langs->trans('RefRequired'), null, 'errors');
	} else {
		$w = new Entrepot($db);
		$w->label       = $child_ref;
		$w->description = $child_desc;
		$w->fk_parent   = $fk_node;
		$w->statut      = 1;
		if ($w->create($user) > 0) {
			setEventMessages($langs->trans('ChildAdded'), null, 'mesgs');
		} else {
			setEventMessages($w->error ?: $db->lasterror(), null, 'errors');
		}
	}
	$action = '';
}

// Deactivate a leaf node
if ($action === 'deactivatenode' && $fk_node > 0) {
	$w = new Entrepot($db);
	if ($w->fetch($fk_node) > 0) {
		$w->statut = 0;
		if ($w->update($fk_node, $user) > 0) {
			setEventMessages($langs->trans('NodeDeactivated'), null, 'mesgs');
		} else {
			setEventMessages($w->error ?: $db->lasterror(), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans('WarehouseNotFound'), null, 'errors');
	}
	$action = '';
}

// ---- VIEW ----

llxHeader('', $langs->trans('WarelocTreeBuilder'), '');

$form = new Form($db);

print dol_get_fiche_head(array(), '', $langs->trans('WarelocTreeBuilder'), -1, 'stock');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('TreeBuilderDesc').'</div>';

// Root warehouse selector
$root_warehouses = wareloc_get_root_warehouses($db);

if (empty($root_warehouses)) {
	print '<div class="warning">'.$langs->trans('NoRootWarehousesFound').'</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

print '<div class="marginbottomonly">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
print '<strong>'.$langs->trans('RootWarehouse').'</strong>: ';
print '<select name="fk_root" class="flat minwidth250" onchange="this.form.submit()">';
print '<option value="0">'.$langs->trans('SelectRootWarehouse').'</option>';
foreach ($root_warehouses as $wh) {
	$sel         = ($fk_root == $wh->rowid) ? ' selected' : '';
	$stock_label = $wh->stock != 0 ? ' ('.price2num($wh->stock, 0).' '.strtolower($langs->trans('Stock')).')' : '';
	print '<option value="'.$wh->rowid.'"'.$sel.'>'.dol_escape_htmltag($wh->ref).$stock_label.'</option>';
}
print '</select>';
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Select').'">';
print '</form>';
print '</div>';

if ($fk_root > 0) {
	$depth_labels = wareloc_get_depth_labels($db, $fk_root);
	$tree         = wareloc_build_tree($fk_root, $db);

	if (!$tree) {
		print '<div class="warning">'.$langs->trans('WarehouseNotFound').'</div>';
	} else {
		$has_children = !empty($tree['children']);

		// ---- Quick-build wizard (only shown before any children exist) ----
		if (!$has_children) {
			if (empty($depth_labels)) {
				print '<div class="info marginbottomonly">';
				print $langs->trans('QuickBuildNeedsLevelNames', dol_buildpath('/wareloc/admin/setup.php?wh='.$fk_root, 1));
				print '</div>';
			} else {
				print '<div class="fichecenter marginbottomonly">';
				print '<div class="underbanner">';
				print '<strong>'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('QuickBuild').'</strong>';
				print ' <span class="opacitymedium small">'.$langs->trans('QuickBuildDesc').'</span>';
				print '</div>';
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="quickbuild">';
				print '<input type="hidden" name="fk_root" value="'.$fk_root.'">';
				print '<table class="noborder" style="width:auto">';
				print '<tr class="liste_titre">';
				print '<td>'.$langs->trans('Depth').'</td>';
				print '<td>'.$langs->trans('LevelName').'</td>';
				print '<td>'.$form->textwithpicto($langs->trans('Count'), $langs->trans('QuickBuildCountDesc')).'</td>';
				print '</tr>';
				foreach ($depth_labels as $d => $label) {
					print '<tr class="oddeven">';
					print '<td class="center opacitymedium">'.$d.'</td>';
					print '<td>'.dol_escape_htmltag($label).'</td>';
					print '<td><input type="number" name="counts['.$d.']" class="flat width75" min="1" max="999" value="1" required></td>';
					print '</tr>';
				}
				print '</table>';
				print '<div class="margintoponly">';
				print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('GenerateTree')).'">';
				print ' <span class="opacitymedium small">'.$langs->trans('QuickBuildWarning').'</span>';
				print '</div>';
				print '</form>';
				print '</div>';
			}
		}

		// ---- Tree header ----
		print '<div class="underbanner marginbottomonly">';
		print '<strong>'.img_picto('', 'stock', 'class="pictofixedwidth"').dol_escape_htmltag($tree['ref']).'</strong>';
		if (!empty($depth_labels)) {
			print ' <span class="opacitymedium small">';
			print $langs->trans('LevelsColon').' ';
			print implode(' &rarr; ', array_map('dol_escape_htmltag', $depth_labels));
			print '</span>';
		}
		if (!empty($depth_labels[1])) {
			print ' <a href="#" class="button smallpaddingimp marginleftonly" onclick="warelocShowBulkAdd('.$fk_root.', \''.dol_escape_js($tree['ref']).'\', \''.dol_escape_js($depth_labels[1] ?? $langs->trans('Child')).'\'); return false;">';
			print img_picto('', 'add', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('AddChildToRoot'));
			print '</a>';
		}
		print '</div>';

		// ---- Recursive tree ----
		print '<div class="wareloc-tree" id="wareloc-tree-root">';
		wareloc_render_tree_node($tree, $depth_labels, $fk_root, $langs, 0);
		print '</div>';

		// ---- Shared bulk-add inline form ----
		print '<div id="wareloc-bulkadd-form" class="wareloc-inline-form" style="display:none">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="bulkaddchildren">';
		print '<input type="hidden" name="fk_root" value="'.$fk_root.'">';
		print '<input type="hidden" name="fk_node" id="bulkadd-fk-node" value="">';
		print '<span id="bulkadd-label" class="opacitymedium"></span> ';
		print '<input type="number" name="bulk_count" id="bulkadd-count" class="flat width75" min="1" max="999" value="1"> ';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
		print ' <button type="button" class="smallpaddingimp" onclick="warelocHideBulkAdd()">'.$langs->trans('Cancel').'</button>';
		print '</form>';
		print '</div>';

		// ---- Shared rename form (submitted via JS) ----
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="wareloc-rename-form">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="renamenode">';
		print '<input type="hidden" name="fk_root" value="'.$fk_root.'">';
		print '<input type="hidden" name="fk_node" id="rename-fk-node" value="">';
		print '<input type="hidden" name="new_ref" id="rename-new-ref" value="">';
		print '</form>';

		// ---- Shared deactivate form (POST, avoids GET token issues) ----
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="wareloc-deactivate-form">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="deactivatenode">';
		print '<input type="hidden" name="fk_root" value="'.$fk_root.'">';
		print '<input type="hidden" name="fk_node" id="deactivate-fk-node" value="">';
		print '</form>';
	}
}

print dol_get_fiche_end();

// ---- CSS ----
print '<style>
.wareloc-tree { font-size: 0.95em; }
.wareloc-tree-node {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 2px;
	border-bottom: 1px solid var(--colorbackbody, #f0f0f0);
}
.wareloc-tree-node:hover { background: var(--colorbacktitle1, #f5f5f5); }
.wareloc-tree-label { color: #999; font-style: italic; min-width: 70px; font-size:0.9em; }
.wareloc-tree-ref { font-weight: 600; min-width: 120px; }
.wareloc-tree-stock { color: #666; font-size:0.9em; }
.wareloc-tree-actions { margin-left: auto; white-space: nowrap; opacity: 0.4; transition: opacity 0.15s; }
.wareloc-tree-node:hover .wareloc-tree-actions { opacity: 1; }
.wareloc-tree-children { margin-left: 20px; border-left: 2px solid #ddd; padding-left: 10px; }
.wareloc-inline-form {
	background: var(--colorbacktitle1, #f8f8f8);
	border: 1px solid #ccc;
	border-radius: 4px;
	padding: 6px 10px;
	margin: 4px 0;
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
</style>';

// ---- JS ----
$js_confirm_deactivate = dol_escape_js($langs->trans('ConfirmDeactivateNode'));
$js_rename_prompt      = dol_escape_js($langs->trans('RenamePrompt'));
$js_add_label          = dol_escape_js($langs->trans('AddLabel'));

print '<script>
function warelocShowBulkAdd(nodeId, parentRef, childLabel) {
	document.getElementById("bulkadd-fk-node").value = nodeId;
	document.getElementById("bulkadd-label").textContent = "'.$js_add_label.'".replace("%s", childLabel).replace("%p", parentRef);
	var form = document.getElementById("wareloc-bulkadd-form");
	form.style.display = "inline-flex";
	var node = document.getElementById("wareloc-node-" + nodeId);
	if (node) node.after(form);
	document.getElementById("bulkadd-count").select();
}
function warelocHideBulkAdd() {
	document.getElementById("wareloc-bulkadd-form").style.display = "none";
}
function warelocRenameNode(nodeId, currentRef) {
	var newRef = window.prompt("'.$js_rename_prompt.'", currentRef);
	if (newRef === null) return;
	newRef = newRef.trim();
	if (!newRef || newRef === currentRef) return;
	document.getElementById("rename-fk-node").value = nodeId;
	document.getElementById("rename-new-ref").value = newRef;
	document.getElementById("wareloc-rename-form").submit();
}
function warelocDeactivateNode(nodeId) {
	if (!window.confirm("'.$js_confirm_deactivate.'")) return;
	document.getElementById("deactivate-fk-node").value = nodeId;
	document.getElementById("wareloc-deactivate-form").submit();
}
</script>';

llxFooter();
$db->close();


// ---- HELPER FUNCTIONS ----

/**
 * Render a single tree node and its children recursively.
 *
 * @param  array     $node          Tree node array with keys rowid, ref, children, etc.
 * @param  array     $depth_labels  Depth-indexed array of level label strings
 * @param  int       $fk_root       Root warehouse ID
 * @param  Translate $langs         Language object
 * @param  int       $display_depth Current display depth (0 = root)
 * @return void
 */
function wareloc_render_tree_node($node, $depth_labels, $fk_root, $langs, $display_depth)
{
	if ($display_depth > 0) {
		$depth_label  = isset($depth_labels[$display_depth]) ? $depth_labels[$display_depth] : ('L'.$display_depth);
		$has_children = !empty($node['children']);
		$stock_str    = ($node['stock'] != 0) ? price2num($node['stock'], 0).' '.strtolower($langs->trans('Stock')) : '';
		$wh_url       = dol_buildpath('/product/stock/card.php?id='.$node['rowid'], 1);
		$next_depth   = $display_depth + 1;
		$next_label   = isset($depth_labels[$next_depth]) ? $depth_labels[$next_depth] : null;

		print '<div class="wareloc-tree-node" id="wareloc-node-'.$node['rowid'].'">';
		print '<span class="wareloc-tree-label">'.$depth_label.'</span>';
		print '<span class="wareloc-tree-ref"><a href="'.$wh_url.'">'.dol_escape_htmltag($node['ref']).'</a>';
		if ($node['statut'] == 0) {
			print ' <span class="badge badge-status0">'.$langs->trans('Closed').'</span>';
		}
		print '</span>';
		if ($node['description']) {
			print '<span class="opacitymedium small">'.dol_escape_htmltag($node['description']).'</span>';
		}
		if ($stock_str) {
			print '<span class="wareloc-tree-stock opacitymedium small">'.$stock_str.'</span>';
		}

		print '<span class="wareloc-tree-actions">';

		// Bulk-add children (shown when a next depth label is defined)
		if ($next_label !== null) {
			print '<a href="#" onclick="warelocShowBulkAdd('.$node['rowid'].', \''.dol_escape_js($node['ref']).'\', \''.dol_escape_js($next_label).'\'); return false;"';
			print ' title="'.dol_escape_htmltag($langs->trans('BulkAddTitle', $next_label)).'">';
			print img_picto('', 'add', 'class="pictofixedwidth"');
			print '</a>';
		}

		// Rename
		print '<a href="#" onclick="warelocRenameNode('.$node['rowid'].', \''.dol_escape_js($node['ref']).'\'); return false;"';
		print ' title="'.$langs->trans('Rename').'">';
		print img_picto('', 'edit', 'class="pictofixedwidth"');
		print '</a>';

		// Deactivate (leaf nodes with no stock only)
		if (!$has_children && $node['stock'] == 0 && $node['statut'] == 1) {
			print '<a href="#" onclick="warelocDeactivateNode('.$node['rowid'].'); return false;"';
			print ' title="'.$langs->trans('Deactivate').'">';
			print img_picto('', 'delete', 'class="pictofixedwidth"');
			print '</a>';
		}

		print '</span>';
		print '</div>';
	}

	if (!empty($node['children'])) {
		if ($display_depth > 0) print '<div class="wareloc-tree-children">';
		foreach ($node['children'] as $child) {
			wareloc_render_tree_node($child, $depth_labels, $fk_root, $langs, $display_depth + 1);
		}
		if ($display_depth > 0) print '</div>';
	}
}

/**
 * Recursive quick-build: create all child warehouses for one depth level.
 *
 * @param  DoliDB $db           Database handler
 * @param  Conf   $conf         Configuration object
 * @param  User   $user         User object
 * @param  int    $parent_id    Parent warehouse ID
 * @param  string $parent_ref   Parent warehouse ref/label
 * @param  array  $depth_labels Depth-indexed array of level label strings
 * @param  array  $counts       Depth-indexed array of counts to create
 * @param  int    $depth        Current depth level
 * @param  int    $created      Counter of created warehouses (passed by reference)
 * @return int    Number of errors (0 = success)
 */
function wareloc_quickbuild_level($db, $conf, $user, $parent_id, $parent_ref, $depth_labels, $counts, $depth, &$created)
{
	if (!isset($counts[$depth]) || (int) $counts[$depth] <= 0) {
		return 0;
	}

	$count     = min((int) $counts[$depth], 999);
	$label_key = isset($depth_labels[$depth]) ? $depth_labels[$depth] : ('L'.$depth);
	$error     = 0;

	for ($i = 1; $i <= $count; $i++) {
		$seg = wareloc_make_ref_segment($label_key, $i, 2);
		$ref = $parent_ref.'-'.$seg;

		$w = new Entrepot($db);
		$w->label     = $ref;
		$w->fk_parent = $parent_id;
		$w->statut    = 1;

		$new_id = $w->create($user);
		if ($new_id <= 0) {
			$error++;
			break;
		}
		$created++;

		$next_depth = $depth + 1;
		if (isset($counts[$next_depth]) && (int) $counts[$next_depth] > 0) {
			$sub_error = wareloc_quickbuild_level($db, $conf, $user, $new_id, $ref, $depth_labels, $counts, $next_depth, $created);
			if ($sub_error) {
				$error += $sub_error;
				break;
			}
		}
	}

	return $error;
}

/**
 * Walk up the parent chain to determine a node's depth relative to the root.
 * Root = depth 0. Its direct children = depth 1. And so on.
 *
 * @param  int     $fk_node  Node warehouse ID
 * @param  int     $fk_root  Root warehouse ID
 * @param  DoliDB  $db       Database handler
 * @return int     Depth (0 if node is root or lookup fails)
 */
function wareloc_get_node_depth($fk_node, $fk_root, $db)
{
	$depth  = 0;
	$cur_id = (int) $fk_node;
	$seen   = array();

	while ($cur_id > 0 && $cur_id !== (int) $fk_root && !isset($seen[$cur_id])) {
		$seen[$cur_id] = true;
		$sql = "SELECT fk_parent FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".$cur_id;
		$res = $db->query($sql);
		if (!$res) break;
		$obj = $db->fetch_object($res);
		$db->free($res);
		if (!$obj) break;
		$depth++;
		$cur_id = (int) $obj->fk_parent;
	}

	return $depth;
}
