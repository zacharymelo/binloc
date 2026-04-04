<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/wareloc.lib.php
 * \ingroup wareloc
 * \brief   Utility functions for Wareloc v2 — hierarchy metadata and tree helpers
 */

/**
 * Build the tab header array for the wareloc admin setup page.
 *
 * @return array  Tab descriptors for dol_get_fiche_head()
 */
function wareloc_admin_prepare_head()
{
	global $langs, $conf;
	$langs->load('wareloc@wareloc');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/wareloc/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('WarelocLevelNames');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'wareloc_admin');

	return $head;
}

/**
 * Return the depth → label map for a given root warehouse (or global default).
 *
 * Resolution: warehouse-specific rows first; fall back to global (fk_entrepot IS NULL).
 *
 * @param  DoliDB|null $db               Database handle (uses global $db if null)
 * @param  int         $fk_entrepot_root Root warehouse ID; 0 = global only
 * @return array<int,string>             depth => label  e.g. [1=>'Row', 2=>'Shelf', 3=>'Bin']
 */
function wareloc_get_depth_labels($db = null, $fk_entrepot_root = 0)
{
	global $conf;
	if ($db === null) {
		global $db;
	}

	$labels = array();

	if ($fk_entrepot_root > 0) {
		$sql = "SELECT depth, label FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND fk_entrepot = ".((int) $fk_entrepot_root);
		$sql .= " ORDER BY depth ASC";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$labels[(int) $obj->depth] = $obj->label;
			}
			$db->free($resql);
		}
		if (!empty($labels)) {
			return $labels;
		}
	}

	$sql = "SELECT depth, label FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND fk_entrepot IS NULL";
	$sql .= " ORDER BY depth ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$labels[(int) $obj->depth] = $obj->label;
		}
		$db->free($resql);
	}

	return $labels;
}

/**
 * Check whether a warehouse has its own depth-label overrides.
 *
 * @param  int         $fk_entrepot  Warehouse ID
 * @param  DoliDB|null $db           Database handle
 * @return bool
 */
function wareloc_warehouse_has_label_overrides($fk_entrepot, $db = null)
{
	global $conf;
	if ($db === null) {
		global $db;
	}

	$sql = "SELECT COUNT(rowid) as cnt FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND fk_entrepot = ".((int) $fk_entrepot);
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		return ($obj->cnt > 0);
	}
	return false;
}

/**
 * Return the fully-labelled path for a warehouse, walking fk_parent upward.
 *
 * Example: bin two levels under "Main Shelving" → "Row: R01 > Shelf: S03 > Bin: B07"
 *
 * @param  int         $fk_entrepot  Leaf (or any) warehouse ID
 * @param  DoliDB|null $db           Database handle
 * @return string
 */
function wareloc_get_full_path_label($fk_entrepot, $db = null)
{
	if ($db === null) {
		global $db;
	}

	$chain  = array();
	$cur_id = (int) $fk_entrepot;
	$seen   = array();

	while ($cur_id > 0 && !isset($seen[$cur_id])) {
		$seen[$cur_id] = true;
		$sql = "SELECT rowid, ref, fk_parent FROM ".MAIN_DB_PREFIX."entrepot";
		$sql .= " WHERE rowid = ".((int) $cur_id);
		$sql .= " AND entity IN (".getEntity('stock').")";
		$resql = $db->query($sql);
		if (!$resql) break;
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) break;
		array_unshift($chain, array('rowid' => (int) $obj->rowid, 'ref' => $obj->ref));
		$cur_id = (int) $obj->fk_parent;
	}

	if (empty($chain)) return '';
	if (count($chain) === 1) return $chain[0]['ref'];

	$root_id      = $chain[0]['rowid'];
	$depth_labels = wareloc_get_depth_labels($db, $root_id);
	$segments     = array();

	foreach (array_slice($chain, 1) as $idx => $node) {
		$depth      = $idx + 1;
		$label      = isset($depth_labels[$depth]) ? $depth_labels[$depth] : ('L'.$depth);
		$segments[] = $label.': '.$node['ref'];
	}

	return implode(' > ', $segments);
}

/**
 * Return all direct children of a warehouse, ordered by ref.
 *
 * @param  int         $fk_parent  Parent warehouse ID
 * @param  DoliDB|null $db         Database handle
 * @return array                   stdClass[] with rowid, ref, statut, stock
 */
function wareloc_get_children($fk_parent, $db = null)
{
	if ($db === null) {
		global $db;
	}

	$rows = array();
	$sql  = "SELECT e.rowid, e.ref, e.statut, e.description,";
	$sql .= " COALESCE(SUM(ps.reel), 0) as stock";
	$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_entrepot = e.rowid";
	$sql .= " WHERE e.fk_parent = ".((int) $fk_parent);
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	$sql .= " GROUP BY e.rowid, e.ref, e.statut, e.description";
	$sql .= " ORDER BY e.ref ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$db->free($resql);
	}
	return $rows;
}

/**
 * Recursively build a full tree array for a root warehouse.
 *
 * @param  int         $fk_root  Root warehouse ID
 * @param  DoliDB|null $db       Database handle
 * @param  int         $depth    Current depth (0 = root)
 * @return array|null            Node: rowid, ref, statut, stock, depth, children[]
 */
function wareloc_build_tree($fk_root, $db = null, $depth = 0)
{
	if ($db === null) {
		global $db;
	}

	$sql  = "SELECT e.rowid, e.ref, e.statut, e.description,";
	$sql .= " COALESCE(SUM(ps.reel), 0) as stock";
	$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_entrepot = e.rowid";
	$sql .= " WHERE e.rowid = ".((int) $fk_root);
	$sql .= " GROUP BY e.rowid, e.ref, e.statut, e.description";
	$resql = $db->query($sql);
	if (!$resql) return null;
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj) return null;

	$node = array(
		'rowid'       => (int) $obj->rowid,
		'ref'         => $obj->ref,
		'statut'      => (int) $obj->statut,
		'description' => $obj->description,
		'stock'       => (float) $obj->stock,
		'depth'       => $depth,
		'children'    => array(),
	);

	foreach (wareloc_get_children($fk_root, $db) as $child) {
		$node['children'][] = wareloc_build_tree($child->rowid, $db, $depth + 1);
	}

	return $node;
}

/**
 * Return all top-level (root) warehouses (fk_parent = 0 or NULL).
 *
 * @param  DoliDB|null $db  Database handle
 * @return array            stdClass[] with rowid, ref, statut, stock
 */
function wareloc_get_root_warehouses($db = null)
{
	if ($db === null) {
		global $db;
	}

	$rows = array();
	$sql  = "SELECT e.rowid, e.ref, e.statut, e.description,";
	$sql .= " COALESCE(SUM(ps.reel), 0) as stock";
	$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_entrepot = e.rowid";
	$sql .= " WHERE (e.fk_parent IS NULL OR e.fk_parent = 0)";
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	$sql .= " GROUP BY e.rowid, e.ref, e.statut, e.description";
	$sql .= " ORDER BY e.ref ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$db->free($resql);
	}
	return $rows;
}

/**
 * Collect all leaf (childless) warehouse IDs under a root, recursively.
 *
 * @param  int         $fk_root  Root warehouse ID
 * @param  DoliDB|null $db       Database handle
 * @return int[]
 */
function wareloc_get_leaf_ids($fk_root, $db = null)
{
	if ($db === null) {
		global $db;
	}
	$tree = wareloc_build_tree($fk_root, $db);
	if (!$tree) return array();
	$leaves = array();
	wareloc_collect_leaves($tree, $leaves);
	return $leaves;
}

/**
 * Recursively collect leaf node IDs from a tree structure
 *
 * @param  array $node    Tree node with 'rowid' and 'children' keys
 * @param  array $leaves  Collected leaf IDs (passed by reference)
 * @return void
 */
function wareloc_collect_leaves($node, &$leaves)
{
	if (empty($node['children'])) {
		$leaves[] = $node['rowid'];
	} else {
		foreach ($node['children'] as $child) {
			wareloc_collect_leaves($child, $leaves);
		}
	}
}

/**
 * Generate a zero-padded ref segment for a child warehouse.
 * wareloc_make_ref_segment('Row', 3, 2) → 'ROW03'
 *
 * @param  string $prefix  Label (e.g. 'Row', 'Toolbox')
 * @param  int    $index   1-based index
 * @param  int    $pad     Zero-padding width
 * @return string
 */
function wareloc_make_ref_segment($prefix, $index, $pad = 2)
{
	$safe = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $prefix), 0, 3));
	return $safe.str_pad((string) $index, $pad, '0', STR_PAD_LEFT);
}
