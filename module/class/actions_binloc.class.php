<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    class/actions_binloc.class.php
 * \ingroup binloc
 * \brief   Binloc hooks — warehouse card, lot/serial card and dispatch integrations
 */

/**
 * Class ActionsBinloc
 *
 * Hook contexts: warehousecard, productlotcard, ordersupplierdispatch.
 * The lot card panel is a proper formObjectOptions field row with AJAX inline
 * editing — no DOM surgery on core markup. All mutations go through the
 * Binloc AJAX endpoints.
 */
class ActionsBinloc
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook: formObjectOptions — warehouse card summary row / lot card location row
	 *
	 * @param  array   $parameters Hook parameters
	 * @param  object  $object     The current card object
	 * @param  string  $action     Current action
	 * @return int                 0 = continue hooks
	 */
	public function formObjectOptions($parameters, &$object, &$action)
	{
		$contexts = isset($parameters['currentcontext']) ? explode(':', $parameters['currentcontext']) : array();

		if (in_array('warehousecard', $contexts)) {
			return $this->_binloc_warehousecard_options($object);
		}
		if (in_array('productlotcard', $contexts)) {
			return $this->_binloc_productlotcard_options($object);
		}

		return 0;
	}

	// =========================================================================
	// warehousecard — show bin location count on warehouse card
	// =========================================================================

	/**
	 * Inject the bin location summary row on the warehouse card
	 *
	 * @param  object $object The Entrepot object
	 * @return int            0
	 */
	private function _binloc_warehousecard_options($object)
	{
		global $langs;

		if (empty($object->id) || $object->id <= 0) {
			return 0;
		}

		dol_include_once('/binloc/lib/binloc.lib.php');
		dol_include_once('/binloc/class/binlocproductlocation.class.php');
		$langs->load('binloc@binloc');

		$levels = binloc_get_warehouse_levels($this->db, $object->id);
		if (empty($levels)) {
			return 0;
		}

		$locObj = new BinlocProductLocation($this->db);
		$count  = $locObj->countByWarehouse($object->id);

		if ($count > 0) {
			$tab_url = dol_buildpath('/binloc/tab_warehouse_locations.php?id='.$object->id, 1);
			$label_strs = array();
			foreach ($levels as $cfg) {
				$label_strs[] = dol_escape_htmltag($cfg->label);
			}
			print '<tr><td>'.$langs->trans('BinLocations').'</td>';
			print '<td><a href="'.$tab_url.'">'.$count.' '.$langs->trans('Products').'</a>';
			print ' <span class="opacitymedium small">('.implode(' &rarr; ', $label_strs).')</span>';
			print '</td></tr>';
		}

		return 0;
	}

	// =========================================================================
	// productlotcard — bin location field row with AJAX inline edit
	// =========================================================================

	/**
	 * Inject the bin location row on the lot/serial card
	 *
	 * @param  object $object The Productlot object
	 * @return int            0
	 */
	private function _binloc_productlotcard_options($object)
	{
		global $langs, $user;

		if (empty($object->id) || $object->id <= 0) {
			return 0;
		}

		dol_include_once('/binloc/lib/binloc.lib.php');
		dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
		dol_include_once('/binloc/class/binlocproductlocation.class.php');
		$langs->load('binloc@binloc');

		$can_write = $user->hasRight('binloc', 'write');

		$locObj = new BinlocProductLocation($this->db);
		$has_location = ($locObj->fetchAnyByLot($object->id) > 0);

		$formatted = '';
		$warehouse_ref = '';
		if ($has_location) {
			$levels = binloc_get_warehouse_levels($this->db, $locObj->fk_entrepot);
			$formatted = $locObj->getFormattedLocation($levels);

			$resql = $this->db->query("SELECT ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".(int) $locObj->fk_entrepot);
			if ($resql) {
				$wh = $this->db->fetch_object($resql);
				$warehouse_ref = $wh ? $wh->ref : '';
				$this->db->free($resql);
			}
		}

		print '<tr><td>'.$langs->trans('BinLocation').'</td>';
		print '<td>';
		binloc_print_assets();

		print '<span id="binloc-lot-panel" data-fk-product="'.(int) $object->fk_product.'"';
		print ' data-fk-entrepot="'.(int) ($has_location ? $locObj->fk_entrepot : 0).'"';
		print ' data-loc-id="'.(int) ($has_location ? $locObj->id : 0).'"';
		print ' data-fk-lot="'.(int) $object->id.'"';
		print ' data-note="'.dol_escape_htmltag((string) ($has_location ? $locObj->note : '')).'">';

		print '<span class="binloc-loc-cell">';
		print '<span class="binloc-loc-display">';
		if ($has_location) {
			$wh_url = dol_buildpath('/product/stock/card.php?id='.$locObj->fk_entrepot, 1);
			print '<strong><a href="'.$wh_url.'">'.dol_escape_htmltag($warehouse_ref).'</a></strong> &mdash; '.dol_escape_htmltag($formatted);
			if ($locObj->note) {
				print ' <span class="opacitymedium small">('.dol_escape_htmltag($locObj->note).')</span>';
			}
		} else {
			print '<span class="opacitymedium">'.$langs->trans('NoBinLocationAssigned').'</span>';
		}
		print '</span>';
		print '</span> ';

		if ($can_write) {
			if ($has_location) {
				print '<a href="#" class="binloc-edit-btn">'.img_picto($langs->trans('EditLocation'), 'edit').'</a> ';
				print '<a href="#" class="binloc-delete-btn">'.img_picto($langs->trans('RemoveLocation'), 'delete').'</a>';
			} else {
				// No location yet: warehouse select + levels appear on demand
				print '<span id="binloc-lot-add" style="display:none">';
				print binloc_render_warehouse_select($this->db, 'binloc_lot_wh', 0, 'flat minwidth150', 'id="binloc-lot-wh"');
				print ' <span id="binloc-lot-levels"></span>';
				print ' <button type="button" class="button smallpaddingimp" id="binloc-lot-save">'.$langs->trans('Save').'</button>';
				print '</span>';
				print '<a href="#" class="button smallpaddingimp" id="binloc-lot-add-btn">'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('AddLocation').'</a>';
			}
		}
		print '</span>';
		print '</td></tr>';

		if ($can_write) {
			print '<script>
jQuery(function ($) {
	Binloc.config.msgSaved = "'.dol_escape_js($langs->trans('LocationSaved')).'";
	Binloc.config.msgRemoved = "'.dol_escape_js($langs->trans('LocationRemoved')).'";
	var $panel = $("#binloc-lot-panel");
	Binloc.bindInlineEdit($panel.parent(), {
		save: "'.dol_escape_js($langs->trans('Save')).'",
		cancel: "'.dol_escape_js($langs->trans('Cancel')).'",
		notePlaceholder: "'.dol_escape_js($langs->trans('LocationNote')).'",
		confirmDelete: "'.dol_escape_js($langs->trans('ConfirmRemoveLocation', '')).'"
	});
	$panel.on("binloc:saved", function () { window.location.reload(); });
	$panel.parent().on("click", ".binloc-delete-btn", function () {
		setTimeout(function () { window.location.reload(); }, 400);
	});

	$("#binloc-lot-add-btn").on("click", function (e) {
		e.preventDefault();
		$(this).hide();
		$("#binloc-lot-add").show();
	});
	$("#binloc-lot-wh").on("change", function () {
		Binloc.swapLevelInputs($("#binloc-lot-levels"), $(this).val(), "lot_", 0);
	});
	$("#binloc-lot-save").on("click", function () {
		var whId = $("#binloc-lot-wh").val();
		if (!whId) { return; }
		var params = $.extend({
			fk_product: $panel.data("fk-product"),
			fk_entrepot: whId,
			fk_product_lot: $panel.data("fk-lot")
		}, Binloc.collectLevelValues($("#binloc-lot-levels"), "lot_"));
		Binloc.saveLocation(params, function () { window.location.reload(); });
	});
});
</script>';
		}

		return 0;
	}

	// =========================================================================
	// ordersupplierdispatch — bin location column on the dispatch page
	// =========================================================================

	/**
	 * Hook: printFieldListTitle — add "Bin Location" column header on dispatch page
	 *
	 * @param  array   $parameters Hook parameters
	 * @param  object  $object     Object
	 * @param  string  $action     Current action
	 * @return int
	 */
	public function printFieldListTitle($parameters, &$object, &$action)
	{
		global $langs;

		$contexts = isset($parameters['currentcontext']) ? explode(':', $parameters['currentcontext']) : array();
		if (!in_array('ordersupplierdispatch', $contexts)) {
			return 0;
		}

		$langs->load('binloc@binloc');
		print '<td>'.$langs->trans('BinLocations').'</td>';

		return 0;
	}

	/**
	 * Hook: printFieldListValue — inject bin location column on dispatch page
	 *
	 * Shows where each product lives (or should go) in the destination
	 * warehouse. The destination is read from Dolibarr's dispatch form field
	 * naming (entrepot + suffix); when that convention does not match, the
	 * cell degrades to a dash instead of breaking the table.
	 *
	 * @param  array   $parameters Hook parameters (includes objp, suffix, i, j)
	 * @param  object  $object     The Reception/SupplierOrder object
	 * @param  string  $action     Current action
	 * @return int
	 */
	public function printFieldListValue($parameters, &$object, &$action)
	{
		$contexts = isset($parameters['currentcontext']) ? explode(':', $parameters['currentcontext']) : array();
		if (!in_array('ordersupplierdispatch', $contexts)) {
			return 0;
		}

		// Only output on the first call per row so the column count stays aligned
		if (isset($parameters['j']) && $parameters['j'] > 0) {
			return 0;
		}

		$objp = isset($parameters['objp']) ? $parameters['objp'] : null;
		if (!$objp || empty($objp->fk_product)) {
			print '<td></td>';
			return 0;
		}

		dol_include_once('/binloc/lib/binloc.lib.php');

		$suffix = isset($parameters['suffix']) ? $parameters['suffix'] : '';
		$fk_entrepot = GETPOSTINT('entrepot'.$suffix);
		if (empty($fk_entrepot) && isset($objp->fk_entrepot)) {
			$fk_entrepot = (int) $objp->fk_entrepot;
		}

		$location_str = '';
		if ($fk_entrepot > 0) {
			$location_str = binloc_format_location($this->db, (int) $objp->fk_product, $fk_entrepot);
		}

		print '<td class="small">';
		if (!empty($location_str)) {
			print dol_escape_htmltag($location_str);
		} else {
			print '<span class="opacitymedium">&mdash;</span>';
		}
		print '</td>';

		return 0;
	}
}
