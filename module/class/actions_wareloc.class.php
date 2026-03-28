<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    class/actions_wareloc.class.php
 * \ingroup wareloc
 * \brief   Wareloc v2 hooks — warehouse card sub-locations tab, reception bin picker
 */

/**
 * Class ActionsWareloc
 *
 * Hook contexts: entrepotcard, receptioncard
 */
class ActionsWareloc
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	// =========================================================================
	// entrepotcard — adds a "Sub-locations" panel to the warehouse card
	// =========================================================================

	/**
	 * Hook: formObjectOptions — inject sub-location tree panel below the warehouse card
	 *
	 * Called on the warehouse card (product/stock/card.php) for both view and edit modes.
	 *
	 * @param  array  $parameters  Hook parameters (currentcontext, object, action, …)
	 * @param  object &$object     The Entrepot object
	 * @param  string &$action     Current action
	 * @return int                 0 = continue hooks, 1 = stop hooks (no error), -1 = error
	 */
	public function formObjectOptions($parameters, &$object, &$action)
	{
		global $langs, $conf, $user;

		if (!in_array('entrepotcard', explode(':', $parameters['currentcontext']))) {
			return 0;
		}
		if (empty($object->id) || $object->id <= 0) {
			return 0;
		}

		dol_include_once('/wareloc/lib/wareloc.lib.php');
		$langs->load('wareloc@wareloc');

		// Only show the sub-location panel for root warehouses (no parent)
		// or warehouses that are themselves a parent (have children)
		$children = wareloc_get_children($object->id, $this->db);
		if (empty($children) && !empty($object->fk_parent)) {
			// This is a leaf node — show its full path label instead
			$path = wareloc_get_full_path_label($object->id, $this->db);
			if ($path) {
				print '<div class="clearboth"></div>';
				print '<div class="marginbottomonly">';
				print '<strong>'.$langs->trans('LocationPath').'</strong>: '.dol_escape_htmltag($path);
				print '</div>';
			}
			return 0;
		}

		// Show sub-location tree summary
		$root_id = empty($object->fk_parent) ? $object->id : $this->_get_root_id($object->id);
		$depth_labels = wareloc_get_depth_labels($this->db, $root_id);

		if (empty($depth_labels) && empty($children)) {
			return 0;
		}

		print '<div class="clearboth"></div>';
		print '<div class="underbanner">';
		print '<strong>'.img_picto('', 'stock', 'class="pictofixedwidth"').$langs->trans('SubLocations').'</strong>';

		if (!empty($depth_labels)) {
			print ' <span class="opacitymedium small">';
			$first_child_label = isset($depth_labels[empty($object->fk_parent) ? 1 : 2]) ? $depth_labels[empty($object->fk_parent) ? 1 : 2] : '';
			if ($first_child_label) {
				print $langs->trans('DirectChildren').': '.dol_escape_htmltag($first_child_label).' '.$langs->trans('Level');
			}
			print '</span>';
		}

		$tree_url = dol_buildpath('/wareloc/warehouse_tree.php?fk_root='.$root_id, 1);
		print ' <a class="button smallpaddingimp marginleftonly" href="'.$tree_url.'">';
		print img_picto('', 'list', 'class="pictofixedwidth"').$langs->trans('ManageTree');
		print '</a>';
		print '</div>';

		if (!empty($children)) {
			print '<table class="noborder" style="width:auto; margin-top:4px">';
			print '<tr class="liste_titre">';
			print '<td>'.dol_escape_htmltag($depth_labels[1] ?? $langs->trans('Child')).'</td>';
			print '<td class="right">'.$langs->trans('Stock').'</td>';
			print '<td></td>';
			print '</tr>';
			foreach ($children as $child) {
				$child_url = dol_buildpath('/product/stock/card.php?id='.$child->rowid, 1);
				print '<tr class="oddeven">';
				print '<td><a href="'.$child_url.'">'.dol_escape_htmltag($child->ref).'</a>';
				if ($child->description) {
					print ' <span class="opacitymedium small">'.dol_escape_htmltag($child->description).'</span>';
				}
				print '</td>';
				print '<td class="right">'.price2num($child->stock, 0).'</td>';
				$grandchildren = wareloc_get_children($child->rowid, $this->db);
				print '<td class="opacitymedium small">'.(!empty($grandchildren) ? count($grandchildren).' sub' : $langs->trans('Leaf')).'</td>';
				print '</tr>';
			}
			print '</table>';
		}

		return 0;
	}

	// =========================================================================
	// receptioncard — bin picker: guide user to a leaf when destination has children
	// =========================================================================

	/**
	 * Hook: formAddObjectLine — inject bin-picker hint on reception lines
	 *
	 * When the destination warehouse on a reception line has children, we render
	 * a small "navigate to bin" tree widget below the native warehouse dropdown.
	 * The selected leaf ID replaces the native warehouse selection via JS.
	 *
	 * @param  array  $parameters  Hook parameters
	 * @param  object &$object     The Reception object
	 * @param  string &$action     Current action
	 * @return int
	 */
	public function formAddObjectLine($parameters, &$object, &$action)
	{
		global $langs, $conf;

		if (!in_array('receptioncard', explode(':', $parameters['currentcontext']))) {
			return 0;
		}

		dol_include_once('/wareloc/lib/wareloc.lib.php');
		$langs->load('wareloc@wareloc');

		// Render a compact JS-driven leaf picker after the warehouse dropdown.
		// We output a hidden <div> containing the tree JSON for each root warehouse;
		// JS monitors the warehouse <select> and shows the appropriate sub-tree.
		$root_warehouses = wareloc_get_root_warehouses($this->db);
		if (empty($root_warehouses)) {
			return 0;
		}

		$trees = array();
		foreach ($root_warehouses as $wh) {
			$children = wareloc_get_children($wh->rowid, $this->db);
			if (!empty($children)) {
				$trees[$wh->rowid] = $this->_build_select_tree($wh->rowid, $this->db);
			}
		}

		if (empty($trees)) {
			return 0;
		}

		// Encode tree data for JS
		$trees_json = json_encode($trees, JSON_HEX_TAG | JSON_HEX_QUOT);

		print '<script>
(function() {
	var warelocTrees = '.$trees_json.';

	function warelocGetLeafOptions(node, depth, depthLabels, prefix) {
		var opts = [];
		if (!node.children || node.children.length === 0) {
			opts.push({ id: node.rowid, label: prefix + node.ref });
		} else {
			(node.children || []).forEach(function(child) {
				var dlabel = depthLabels[depth] || ("L" + depth);
				opts = opts.concat(warelocGetLeafOptions(child, depth + 1, depthLabels, prefix + node.ref + " > "));
			});
		}
		return opts;
	}

	function warelocAttachBinPicker(selectEl) {
		if (selectEl.dataset.warelocAttached) return;
		selectEl.dataset.warelocAttached = "1";

		var wrapper = document.createElement("div");
		wrapper.id = "wareloc-bin-picker";
		wrapper.style.marginTop = "4px";
		wrapper.style.display = "none";
		selectEl.parentNode.insertBefore(wrapper, selectEl.nextSibling);

		selectEl.addEventListener("change", function() {
			var val = parseInt(this.value);
			wrapper.innerHTML = "";
			wrapper.style.display = "none";
			if (warelocTrees[val]) {
				var node = warelocTrees[val];
				var sel = document.createElement("select");
				sel.className = "flat minwidth250";
				sel.title = "'.dol_escape_js($langs->trans('BinPickerTitle')).'";
				var placeholder = document.createElement("option");
				placeholder.value = "";
				placeholder.textContent = "'.dol_escape_js($langs->trans('SelectBin')).'";
				sel.appendChild(placeholder);
				var leaves = warelocGetLeafOptions(node, 1, {}, "");
				leaves.forEach(function(leaf) {
					var opt = document.createElement("option");
					opt.value = leaf.id;
					opt.textContent = leaf.label;
					sel.appendChild(opt);
				});
				sel.addEventListener("change", function() {
					if (this.value) {
						selectEl.value = this.value;
					}
				});
				var label = document.createElement("span");
				label.className = "opacitymedium small";
				label.style.marginLeft = "6px";
				label.textContent = "'.dol_escape_js($langs->trans('BinPickerHint')).'";
				wrapper.appendChild(sel);
				wrapper.appendChild(label);
				wrapper.style.display = "block";
			}
		});
	}

	// Attach to warehouse selects when DOM ready
	function warelocInitBinPickers() {
		document.querySelectorAll("select[name*=entrepot], select[name*=warehouse], select#idwarehouse").forEach(function(el) {
			warelocAttachBinPicker(el);
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", warelocInitBinPickers);
	} else {
		warelocInitBinPickers();
	}
})();
</script>';

		return 0;
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Walk up fk_parent chain to find the root warehouse ID.
	 *
	 * @param  int $fk_entrepot
	 * @return int
	 */
	private function _get_root_id($fk_entrepot)
	{
		$cur  = (int) $fk_entrepot;
		$seen = array();
		while ($cur > 0 && !isset($seen[$cur])) {
			$seen[$cur] = true;
			$sql = "SELECT fk_parent FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".$cur;
			$res = $this->db->query($sql);
			if (!$res) break;
			$obj = $this->db->fetch_object($res);
			$this->db->free($res);
			if (!$obj || !$obj->fk_parent) break;
			$cur = (int) $obj->fk_parent;
		}
		return $cur;
	}

	/**
	 * Build a lightweight nested array for the bin picker JS (id, ref, children[]).
	 *
	 * @param  int     $fk_root
	 * @param  DoliDB  $db
	 * @return array
	 */
	private function _build_select_tree($fk_root, $db)
	{
		dol_include_once('/wareloc/lib/wareloc.lib.php');

		$sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $fk_root);
		$res = $db->query($sql);
		if (!$res) return array();
		$obj = $db->fetch_object($res);
		$db->free($res);
		if (!$obj) return array();

		$node = array('rowid' => (int) $obj->rowid, 'ref' => $obj->ref, 'children' => array());
		foreach (wareloc_get_children($fk_root, $db) as $child) {
			$node['children'][] = $this->_build_select_tree($child->rowid, $db);
		}
		return $node;
	}
}
