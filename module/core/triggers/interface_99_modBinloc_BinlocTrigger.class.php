<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/triggers/interface_99_modBinloc_BinlocTrigger.class.php
 * \ingroup binloc
 * \brief   Trigger for Binloc — clears locations on stock movements and lot deletion
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceBinlocTrigger
 *
 * Listens for STOCK_MOVEMENT and PRODUCTLOT_DELETE events.
 *
 * Rules:
 * - For serialized products (movement has a batch): always clear the lot's location
 *   when it leaves a warehouse, regardless of the BINLOC_CLEAR_ON_ZERO_STOCK setting.
 *   A serial physically exists in exactly one place, so moving it elsewhere voids
 *   its prior location record.
 * - For non-serialized: only clear when BINLOC_CLEAR_ON_ZERO_STOCK is enabled and
 *   the product's stock in the warehouse has dropped to zero. Lot rows are left
 *   alone by this path.
 * - PRODUCTLOT_DELETE: remove the lot's location rows. There is no DB cascade for
 *   this in v2 — fk_product_lot uses 0 as "no lot", which precludes a lot FK.
 */
class InterfaceBinlocTrigger extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name        = preg_replace('/^Interface/i', '', get_class($this));
		$this->family      = 'stock';
		$this->description = 'Binloc trigger — clears bin locations on stock movements and lot deletion';
		$this->version     = '2.2.0';
		$this->picto       = 'stock';
	}

	/**
	 * Function called when a Dolibarr business event is thrown
	 *
	 * @param  string    $action   Event action code
	 * @param  object    $object   Object the event is about
	 * @param  User      $user     User performing the action
	 * @param  Translate $langs    Language object
	 * @param  Conf      $conf     Configuration object
	 * @return int                 0 = OK, <0 = KO
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('binloc')) {
			return 0;
		}

		if ($action === 'PRODUCTLOT_DELETE') {
			$lot_id = isset($object->id) ? (int) $object->id : 0;
			if ($lot_id > 0) {
				dol_include_once('/binloc/class/binlocproductlocation.class.php');
				$loc = new BinlocProductLocation($this->db);
				$loc->deleteByLot($lot_id);
			}
			return 0;
		}

		if ($action !== 'STOCK_MOVEMENT') {
			return 0;
		}

		$fk_product  = isset($object->product_id) ? (int) $object->product_id : 0;
		$fk_entrepot = isset($object->warehouse_id) ? (int) $object->warehouse_id : 0;

		if ($fk_product <= 0 || $fk_entrepot <= 0) {
			return 0;
		}

		dol_include_once('/binloc/class/binlocproductlocation.class.php');

		// Check if this movement involves a batch/serial
		$batch = isset($object->batch) ? trim((string) $object->batch) : '';
		$qty   = isset($object->qty) ? (float) $object->qty : 0;
		$type  = isset($object->type) ? (int) $object->type : 0;

		if (!empty($batch)) {
			// Serialized movement — find the lot ID and clear its location
			// if this is a stock-out event (qty negative or type indicating out).
			// Type codes: 0=in, 1=out, 2=in-correction, 3=out-correction
			$is_out = ($qty < 0) || ($type === 1) || ($type === 3);

			if ($is_out) {
				$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_lot";
				$sql .= " WHERE fk_product = ".$fk_product;
				$sql .= " AND batch = '".$this->db->escape($batch)."'";
				$sql .= " AND entity IN (".getEntity('stock').")";

				$resql = $this->db->query($sql);
				if ($resql) {
					$lot_obj = $this->db->fetch_object($resql);
					$this->db->free($resql);

					if ($lot_obj && (int) $lot_obj->rowid > 0) {
						// Clear this lot's location — the serial has moved away
						$del = "DELETE FROM ".MAIN_DB_PREFIX."binloc_product_location";
						$del .= " WHERE fk_product_lot = ".(int) $lot_obj->rowid;
						$del .= " AND fk_entrepot = ".$fk_entrepot;
						$del .= " AND entity IN (".getEntity('stock').")";
						$this->db->query($del);
					}
				}
			}
			return 0;
		}

		// Non-serialized path — honor BINLOC_CLEAR_ON_ZERO_STOCK setting
		if (!getDolGlobalInt('BINLOC_CLEAR_ON_ZERO_STOCK')) {
			return 0;
		}

		$sql = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock";
		$sql .= " WHERE fk_product = ".$fk_product;
		$sql .= " AND fk_entrepot = ".$fk_entrepot;

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj || (float) $obj->reel <= 0) {
			$loc = new BinlocProductLocation($this->db);
			$loc->clearIfZeroStock($fk_product, $fk_entrepot, $user);
		}

		return 0;
	}
}
