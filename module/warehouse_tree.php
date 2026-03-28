<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    warehouse_tree.php
 * \ingroup wareloc
 * \brief   Warehouse hierarchy tree builder — view, manage, and quick-build bin trees
 */

$res = 0;
if (!$res && file_exists("../main.inc.php"))    { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/wareloc/lib/wareloc.lib.php');

$langs->loadLangs(array('stocks', 'wareloc@wareloc'));

if (!$user->admin) {
	accessforbidden();
}

$action   = GETPOST('action', 'aZ09');
$fk_root  = GETPOSTINT('fk_root');  // selected root warehouse
$fk_node  = GETPOSTINT('fk_node');  // node to act on

// ---- ACTIONS ----

// Quick-build: auto-generate the full tree under a root warehouse
if ($action === 'quickbuild' && $fk_root > 0) {
	$depth_labels = wareloc_get_depth_labels($db, $fk_root);
	if (empty($depth_labels)) {
		setEventMessages($langs->trans('NoLevelNamesDefined'), null, 'warnings');
		$action = '';
	} else {
		// Read counts per depth from POST: counts[1]=3, counts[2]=5, counts[3]=8 etc.
		$counts = GETPOST('counts', 'array');
		if (!is_array($counts) || empty($counts)) {
			setEventMessages($langs->trans('QuickBuildCountsRequired'), null, 'errors');
			$action = '';
		} else {
			// Fetch root ref for prefix building
			$root_obj = new Entrepot($db);
			if ($root_obj->fetch($fk_root) <= 0) {
				setEventMessages($langs->trans('WarehouseNotFound'), null, 'errors');
				$action = '';
			} else {
				$db->begin();
				$error = 0;
				$created = 0;

				// Recursively create children; $parent_id is current parent, $depth is 1-based
				$error = _wareloc_quickbuild_level($db, $conf, $user, $fk_root, $root_obj->ref, $depth_labels, $counts, 1, $created);

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

// Rename a warehouse node
if ($action === 'renamenode' && $fk_node > 0) {
	$new_ref = GETPOST('new_ref', 'alpha');
	if (empty($new_ref)) {
		setEventMessages($langs->trans('RefRequired'), null, 'errors');
	} else {
		$w = new Entrepot($db);
		if ($w->fetch($fk_node) > 0) {
			$w->label = $new_ref;
			if ($w->update($user) >= 0) {
				setEventMessages($langs->trans('NodeRenamed'), null, 'mesgs');
			} else {
				setEventMessages($w->error, null, 'errors');
			}
		}
	}
	$action = '';
}

// Add a single child under a node
if ($action === 'addchild' && $fk_node > 0) {
	$child_ref = GETPOST('child_ref', 'alpha');
	$child_desc = GETPOST('child_desc', 'alphanohtml');
	if (empty($child_ref)) {
		setEventMessages($langs->trans('RefRequired'), null, 'errors');
	} else {
		$w = new Entrepot($db);
		$w->label      = $child_ref;
		$w->description = $child_desc;
		$w->fk_parent  = $fk_node;
		$w->statut     = 1;
		if ($w->create($user) > 0) {
			setEventMessages($langs->trans('ChildAdded'), null, 'mesgs');
		} else {
			setEventMessages($w->error ?: $db->lasterror(), null, 'errors');
		}
	}
	$action = '';
}

// Deactivate a node (and warn if it has stock or children)
if ($action === 'deactivatenode' && $fk_node > 0) {
	$w = new Entrepot($db);
	if ($w->fetch($fk_node) > 0) {
		$w->statut = 0;
		if ($w->update($user) >= 0) {
			setEventMessages($langs->trans('NodeDeactivated'), null, 'mesgs');
		} else {
			setEventMessages($w->error, null, 'errors');
		}
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
	$sel = ($fk_root == $wh->rowid) ? ' selected' : '';
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
		// ---- Quick-build wizard (shown only when tree has no children yet) ----
		$has_children = !empty($tree['children']);

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

		// ---- Tree view ----

		print '<div class="underbanner marginbottomonly">';
		print '<strong>'.img_picto('', 'stock', 'class="pictofixedwidth"').dol_escape_htmltag($tree['ref']).'</strong>';
		if (!empty($depth_labels)) {
			print ' <span class="opacitymedium small">';
			print $langs->trans('LevelsColon').' ';
			print implode(' → ', array_map('dol_escape_htmltag', $depth_labels));
			print '</span>';
		}
		print '</div>';

		// Add single child button at root level
		print '<div class="marginbottomonly">';
		print '<a href="#" class="button smallpaddingimp" onclick="warelocShowAddChild('.$fk_root.', \''.dol_escape_js($depth_labels[1] ?? $langs->trans('Child')).'\'); return false;">';
		print img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('AddChildToRoot');
		print '</a>';
		print '</div>';

		// Recursive tree rendering
		print '<div class="wareloc-tree" id="wareloc-tree-root">';
		_wareloc_render_tree_node($tree, $depth_labels, $fk_root, $langs, 0);
		print '</div>';

		// Add-child inline form (hidden, shown via JS)
		print '<div id="wareloc-addchild-form" class="wareloc-inline-form" style="display:none">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="addchild">';
		print '<input type="hidden" name="fk_root" value="'.$fk_root.'">';
		print '<input type="hidden" name="fk_node" id="addchild-fk-node" value="">';
		print '<strong id="addchild-label"></strong> ';
		print '<input type="text" name="child_ref" id="addchild-ref" class="flat minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('RefPlaceholder')).'" autocomplete="off">';
		print ' <input type="text" name="child_desc" class="flat minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('DescriptionOptional')).'">';
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Add').'">';
		print ' <button type="button" class="smallpaddingimp" onclick="warelocHideAddChild()">'.$langs->trans('Cancel').'</button>';
		print '</form>';
		print '</div>';
	}
}

print dol_get_fiche_end();

// ---- CSS + JS ----

print '<style>
.wareloc-tree { font-size: 0.95em; }
.wareloc-tree-node {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 3px 0;
	border-bottom: 1px solid var(--colorbackbody, #f0f0f0);
}
.wareloc-tree-node:hover { background: var(--colorbacktitle1, #f5f5f5); }
.wareloc-tree-indent { display: inline-block; }
.wareloc-tree-ref { font-weight: 600; min-width: 120px; }
.wareloc-tree-label { color: #888; font-style: italic; min-width: 80px; }
.wareloc-tree-stock { min-width: 80px; text-align: right; color: #555; }
.wareloc-tree-actions { margin-left: auto; white-space: nowrap; }
.wareloc-tree-children { margin-left: 24px; border-left: 2px solid var(--colortextlink, #ccc); padding-left: 8px; }
.wareloc-inline-form {
	background: var(--colorbacktitle1, #f8f8f8);
	border: 1px solid #ccc;
	border-radius: 4px;
	padding: 8px 12px;
	margin: 6px 0;
	display: inline-block;
}
</style>';

print '<script>
function warelocShowAddChild(nodeId, levelLabel) {
	document.getElementById("addchild-fk-node").value = nodeId;
	document.getElementById("addchild-label").textContent = levelLabel + ":";
	var form = document.getElementById("wareloc-addchild-form");
	form.style.display = "block";
	document.getElementById("addchild-ref").focus();
	// Move form near the clicked node
	var node = document.getElementById("wareloc-node-" + nodeId);
	if (node) node.after(form);
}
function warelocHideAddChild() {
	document.getElementById("wareloc-addchild-form").style.display = "none";
}
</script>';

llxFooter();
$db->close();


// ---- HELPER FUNCTIONS ----

/**
 * Render a single tree node and its children recursively.
 *
 * @param  array              $node          Tree node from wareloc_build_tree()
 * @param  array<int,string>  $depth_labels  Depth → label map for this root
 * @param  int                $fk_root       Root warehouse ID (for URL params)
 * @param  Translate          $langs         Lang object
 * @param  int                $display_depth Display depth (0 = root, skip rendering; 1+ = children)
 * @return void
 */
function _wareloc_render_tree_node($node, $depth_labels, $fk_root, $langs, $display_depth)
{
	// Don't render the root row itself — it's already shown as the header
	if ($display_depth > 0) {
		$depth_label = isset($depth_labels[$display_depth]) ? $depth_labels[$display_depth] : ('L'.$display_depth);
		$indent_px   = ($display_depth - 1) * 0;
		$has_children = !empty($node['children']);
		$stock_str    = ($node['stock'] != 0) ? price2num($node['stock'], 0).' '.strtolower($langs->trans('Stock')) : '';
		$wh_url       = dol_buildpath('/product/stock/card.php?id='.$node['rowid'], 1);

		print '<div class="wareloc-tree-node" id="wareloc-node-'.$node['rowid'].'">';
		print '<span class="wareloc-tree-label opacitymedium">'.$depth_label.'</span>';
		print '<span class="wareloc-tree-ref"><a href="'.$wh_url.'">'.dol_escape_htmltag($node['ref']).'</a>';
		if ($node['statut'] == 0) {
			print ' <span class="badge badge-status0">'.$langs->trans('Closed').'</span>';
		}
		print '</span>';
		if ($node['description']) {
			print '<span class="opacitymedium small">'.dol_escape_htmltag($node['description']).'</span>';
		}
		if ($stock_str) {
			print '<span class="wareloc-tree-stock">'.$stock_str.'</span>';
		}

		print '<span class="wareloc-tree-actions">';

		// Add child (only if there's a next depth level defined)
		$next_depth = $display_depth + 1;
		$next_label = isset($depth_labels[$next_depth]) ? $depth_labels[$next_depth] : null;
		if ($next_label !== null) {
			print '<a href="#" onclick="warelocShowAddChild('.$node['rowid'].', \''.dol_escape_js($next_label).'\'); return false;" title="'.dol_escape_htmltag($langs->trans('AddChildNode', $next_label)).'">';
			print img_picto('', 'add', 'class="pictofixedwidth"');
			print '</a>';
		}

		// Rename
		$rename_url = $_SERVER['PHP_SELF'].'?action=renamenode&fk_node='.$node['rowid'].'&fk_root='.$fk_root.'&token='.newToken();
		print '<a href="#" onclick="warelocRenameNode('.$node['rowid'].', \''.dol_escape_js($node['ref']).'\', \''.$rename_url.'\'); return false;" title="'.$langs->trans('Rename').'">';
		print img_picto('', 'edit', 'class="pictofixedwidth"');
		print '</a>';

		// Deactivate (only if leaf and no stock)
		if (!$has_children && $node['stock'] == 0 && $node['statut'] == 1) {
			$deact_url = $_SERVER['PHP_SELF'].'?action=deactivatenode&fk_node='.$node['rowid'].'&fk_root='.$fk_root.'&token='.newToken();
			print '<a href="'.$deact_url.'" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmDeactivateNode')).'\')" title="'.$langs->trans('Deactivate').'">';
			print img_picto('', 'delete', 'class="pictofixedwidth"');
			print '</a>';
		}

		print '</span>';
		print '</div>';
	}

	// Children
	if (!empty($node['children'])) {
		if ($display_depth > 0) {
			print '<div class="wareloc-tree-children">';
		}
		foreach ($node['children'] as $child) {
			_wareloc_render_tree_node($child, $depth_labels, $fk_root, $langs, $display_depth + 1);
		}
		if ($display_depth > 0) {
			print '</div>';
		}
	}
}

/**
 * Recursive quick-build helper: creates all child warehouses for one depth level.
 *
 * @param  DoliDB             $db
 * @param  Conf               $conf
 * @param  User               $user
 * @param  int                $parent_id      Parent warehouse ID
 * @param  string             $parent_ref     Parent ref (used as prefix for child refs)
 * @param  array<int,string>  $depth_labels   depth → label
 * @param  array              $counts         depth → count (from POST)
 * @param  int                $depth          Current depth being created (1-based)
 * @param  int                &$created       Running count of created records
 * @return int                0 = success, >0 = error count
 */
function _wareloc_quickbuild_level($db, $conf, $user, $parent_id, $parent_ref, $depth_labels, $counts, $depth, &$created)
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

		// Recurse into next depth
		$next_depth = $depth + 1;
		if (isset($counts[$next_depth]) && (int) $counts[$next_depth] > 0) {
			$sub_error = _wareloc_quickbuild_level($db, $conf, $user, $new_id, $ref, $depth_labels, $counts, $next_depth, $created);
			if ($sub_error) {
				$error += $sub_error;
				break;
			}
		}
	}

	return $error;
}
