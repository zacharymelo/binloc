<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    class/binlocproductlocation.class.php
 * \ingroup binloc
 * \brief   Business class for product location assignments
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class BinlocProductLocation
 *
 * Manages product location data within warehouses.
 * One record per product per warehouse for non-serialized products.
 * One record per product-lot per warehouse for serialized/batch products.
 */
class BinlocProductLocation extends CommonObject
{
	/** @var string */
	public $element = 'binlocproductlocation';

	/** @var string */
	public $table_element = 'binloc_product_location';

	/** @var int */
	public $fk_product;

	/** @var int */
	public $fk_entrepot;

	/** @var int|null Lot/serial ID (null for non-serialized products) */
	public $fk_product_lot;

	/** @var string */
	public $level1_value;

	/** @var string */
	public $level2_value;

	/** @var string */
	public $level3_value;

	/** @var string */
	public $level4_value;

	/** @var string */
	public $level5_value;

	/** @var string */
	public $level6_value;

	/** @var string */
	public $note;

	/** @var int */
	public $fk_user_creat;

	/** @var int */
	public $fk_user_modif;

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
	 * Create a product location record
	 *
	 * Enforcement:
	 * - For serialized products (fk_product_lot set): a serial can have ONLY ONE location anywhere.
	 *   If one already exists in any warehouse, create() refuses.
	 * - For non-serialized: one row per (product, warehouse) as before.
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function create($user)
	{
		if (!empty($this->fk_product_lot)) {
			// One serial = one location. Check for any existing row for this lot.
			$existing_id = $this->fetchAnyByLot($this->fk_product_lot);
			if ($existing_id > 0) {
				$this->error = 'SerialAlreadyLocated';
				return -1;
			}
		} else {
			// Non-serialized: unique on (product, warehouse, NULL lot)
			$existing = $this->fetchByProductWarehouseLot($this->fk_product, $this->fk_entrepot, null);
			if ($existing > 0) {
				$this->error = 'Duplicate: location already exists for this product/warehouse';
				return -1;
			}
		}

		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity, fk_product, fk_entrepot, fk_product_lot,";
		$sql .= " level1_value, level2_value, level3_value,";
		$sql .= " level4_value, level5_value, level6_value,";
		$sql .= " note, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (int) getEntity('stock');
		$sql .= ", ".(int) $this->fk_product;
		$sql .= ", ".(int) $this->fk_entrepot;
		$sql .= ", ".(!empty($this->fk_product_lot) ? (int) $this->fk_product_lot : "NULL");
		for ($i = 1; $i <= 6; $i++) {
			$val = $this->{'level'.$i.'_value'};
			$sql .= ", ".($val !== null && $val !== '' ? "'".$this->db->escape($val)."'" : "NULL");
		}
		$sql .= ", ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", ".(int) $user->id;
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->fk_user_creat = $user->id;

		return $this->id;
	}

	/**
	 * Update a product location record
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function update($user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		for ($i = 1; $i <= 6; $i++) {
			$val = $this->{'level'.$i.'_value'};
			$sql .= " level".$i."_value = ".($val !== null && $val !== '' ? "'".$this->db->escape($val)."'" : "NULL").",";
		}
		$sql .= " note = ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL").",";
		$sql .= " fk_user_modif = ".(int) $user->id;
		$sql .= " WHERE rowid = ".(int) $this->id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Fetch by ID
	 *
	 * @param  int $id Record ID
	 * @return int     >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, fk_product, fk_entrepot, fk_product_lot,";
		$sql .= " level1_value, level2_value, level3_value,";
		$sql .= " level4_value, level5_value, level6_value,";
		$sql .= " note, date_creation, fk_user_creat, fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".(int) $id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			return 0;
		}

		$this->id              = (int) $obj->rowid;
		$this->entity          = (int) $obj->entity;
		$this->fk_product      = (int) $obj->fk_product;
		$this->fk_entrepot     = (int) $obj->fk_entrepot;
		$this->fk_product_lot  = $obj->fk_product_lot ? (int) $obj->fk_product_lot : null;
		for ($i = 1; $i <= 6; $i++) {
			$this->{'level'.$i.'_value'} = $obj->{'level'.$i.'_value'};
		}
		$this->note          = $obj->note;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = $obj->fk_user_modif ? (int) $obj->fk_user_modif : null;

		return 1;
	}

	/**
	 * Fetch any location row by lot ID (across all warehouses)
	 *
	 * Used to enforce "one serial - one location" rule: a serial can only exist
	 * in one place at a time regardless of warehouse.
	 *
	 * @param  int $fk_product_lot Lot/serial ID
	 * @return int                 >0 if OK and loaded, 0 if not found, <0 if KO
	 */
	public function fetchAnyByLot($fk_product_lot)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product_lot = ".(int) $fk_product_lot;
		$sql .= " AND entity IN (".getEntity('stock').")";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			return 0;
		}

		return $this->fetch($obj->rowid);
	}

	/**
	 * Fetch by product + warehouse + lot combination
	 *
	 * @param  int      $fk_product      Product ID
	 * @param  int      $fk_entrepot     Warehouse ID
	 * @param  int|null $fk_product_lot  Lot/serial ID (null for non-serialized)
	 * @return int                        >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetchByProductWarehouseLot($fk_product, $fk_entrepot, $fk_product_lot = null)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product = ".(int) $fk_product;
		$sql .= " AND fk_entrepot = ".(int) $fk_entrepot;
		if (!empty($fk_product_lot)) {
			$sql .= " AND fk_product_lot = ".(int) $fk_product_lot;
		} else {
			$sql .= " AND fk_product_lot IS NULL";
		}
		$sql .= " AND entity IN (".getEntity('stock').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			return 0;
		}

		return $this->fetch($obj->rowid);
	}

	/**
	 * Fetch by product + warehouse (backward-compatible, returns first match ignoring lot)
	 *
	 * For non-serialized products this returns the single record.
	 * For serialized products this returns the first matching record (lot-unaware).
	 *
	 * @param  int $fk_product   Product ID
	 * @param  int $fk_entrepot  Warehouse ID
	 * @return int               >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetchByProductWarehouse($fk_product, $fk_entrepot)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product = ".(int) $fk_product;
		$sql .= " AND fk_entrepot = ".(int) $fk_entrepot;
		$sql .= " AND fk_product_lot IS NULL";
		$sql .= " AND entity IN (".getEntity('stock').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			return 0;
		}

		return $this->fetch($obj->rowid);
	}

	/**
	 * Fetch all location records for a product (across all warehouses, all lots)
	 *
	 * @param  int   $fk_product Product ID
	 * @return array             Array of result objects
	 */
	public function fetchAllByProduct($fk_product)
	{
		$results = array();

		$sql = "SELECT pl.rowid, e.ref as warehouse_ref, e.lieu as warehouse_label,";
		$sql .= " pl.fk_entrepot, pl.fk_product_lot,";
		$sql .= " lot.batch as lot_batch,";
		$sql .= " pl.level1_value, pl.level2_value, pl.level3_value,";
		$sql .= " pl.level4_value, pl.level5_value, pl.level6_value,";
		$sql .= " pl.note,";
		$sql .= " IFNULL(ps.reel, 0) as stock";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as pl";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = pl.fk_entrepot";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON (ps.fk_product = pl.fk_product AND ps.fk_entrepot = pl.fk_entrepot)";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as lot ON lot.rowid = pl.fk_product_lot";
		$sql .= " WHERE pl.fk_product = ".(int) $fk_product;
		$sql .= " AND pl.entity IN (".getEntity('stock').")";
		$sql .= " ORDER BY e.ref ASC, lot.batch ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $results;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$row = new stdClass();
			$row->rowid           = (int) $obj->rowid;
			$row->fk_entrepot     = (int) $obj->fk_entrepot;
			$row->fk_product_lot  = $obj->fk_product_lot ? (int) $obj->fk_product_lot : null;
			$row->lot_batch       = $obj->lot_batch;
			$row->warehouse_ref   = $obj->warehouse_ref;
			$row->warehouse_label = $obj->warehouse_label;
			$row->stock           = (float) $obj->stock;
			$row->note            = $obj->note;
			for ($i = 1; $i <= 6; $i++) {
				$row->{'level'.$i.'_value'} = $obj->{'level'.$i.'_value'};
			}
			$results[] = $row;
		}
		$this->db->free($resql);

		return $results;
	}

	/**
	 * Fetch all products with locations in a warehouse
	 *
	 * @param  int    $fk_entrepot Warehouse ID
	 * @param  string $search      Optional product ref/label search filter
	 * @param  string $sortfield   Sort field
	 * @param  string $sortorder   Sort order (ASC/DESC)
	 * @param  int    $limit       Max rows
	 * @param  int    $offset      Offset
	 * @return array               Array of result objects
	 */
	public function fetchAllByWarehouse($fk_entrepot, $search = '', $sortfield = 'p.ref', $sortorder = 'ASC', $limit = 0, $offset = 0)
	{
		$results = array();

		$sql = "SELECT pl.rowid, pl.fk_product, pl.fk_product_lot,";
		$sql .= " p.ref as product_ref, p.label as product_label,";
		$sql .= " lot.batch as lot_batch,";
		$sql .= " pl.level1_value, pl.level2_value, pl.level3_value,";
		$sql .= " pl.level4_value, pl.level5_value, pl.level6_value,";
		$sql .= " pl.note,";
		$sql .= " IFNULL(ps.reel, 0) as stock";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as pl";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pl.fk_product";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON (ps.fk_product = pl.fk_product AND ps.fk_entrepot = pl.fk_entrepot)";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as lot ON lot.rowid = pl.fk_product_lot";
		$sql .= " WHERE pl.fk_entrepot = ".(int) $fk_entrepot;
		$sql .= " AND pl.entity IN (".getEntity('stock').")";

		if (!empty($search)) {
			$sql .= " AND (p.ref LIKE '%".$this->db->escape($search)."%'";
			$sql .= " OR p.label LIKE '%".$this->db->escape($search)."%'";
			$sql .= " OR lot.batch LIKE '%".$this->db->escape($search)."%')";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $results;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$row = new stdClass();
			$row->rowid          = (int) $obj->rowid;
			$row->fk_product     = (int) $obj->fk_product;
			$row->fk_product_lot = $obj->fk_product_lot ? (int) $obj->fk_product_lot : null;
			$row->lot_batch      = $obj->lot_batch;
			$row->product_ref    = $obj->product_ref;
			$row->product_label  = $obj->product_label;
			$row->stock          = (float) $obj->stock;
			$row->note           = $obj->note;
			for ($i = 1; $i <= 6; $i++) {
				$row->{'level'.$i.'_value'} = $obj->{'level'.$i.'_value'};
			}
			$results[] = $row;
		}
		$this->db->free($resql);

		return $results;
	}

	/**
	 * Count products with locations in a warehouse
	 *
	 * @param  int    $fk_entrepot Warehouse ID
	 * @param  string $search      Optional search filter
	 * @return int                  Count or -1 on error
	 */
	public function countByWarehouse($fk_entrepot, $search = '')
	{
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX.$this->table_element." as pl";
		if (!empty($search)) {
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pl.fk_product";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as lot ON lot.rowid = pl.fk_product_lot";
		}
		$sql .= " WHERE pl.fk_entrepot = ".(int) $fk_entrepot;
		$sql .= " AND pl.entity IN (".getEntity('stock').")";

		if (!empty($search)) {
			$sql .= " AND (p.ref LIKE '%".$this->db->escape($search)."%'";
			$sql .= " OR p.label LIKE '%".$this->db->escape($search)."%'";
			$sql .= " OR lot.batch LIKE '%".$this->db->escape($search)."%')";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return (int) $obj->nb;
	}

	/**
	 * Delete a product location record
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function delete($user)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".(int) $this->id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Delete all location records for a product/warehouse when stock drops to zero
	 *
	 * @param  int  $fk_product   Product ID
	 * @param  int  $fk_entrepot  Warehouse ID
	 * @param  User $user         User performing action
	 * @return int                >0 if deleted, 0 if not found, <0 if error
	 */
	public function clearIfZeroStock($fk_product, $fk_entrepot, $user)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product = ".(int) $fk_product;
		$sql .= " AND fk_entrepot = ".(int) $fk_entrepot;
		$sql .= " AND entity IN (".getEntity('stock').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return $this->db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Create or update a location record (upsert)
	 *
	 * - For serialized (fk_product_lot set): finds the lot's existing location (any warehouse)
	 *   and updates it — including reassigning fk_entrepot if the serial moved. Enforces the
	 *   one-serial-one-location rule.
	 * - For non-serialized: matches on (product, warehouse).
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function createOrUpdate($user)
	{
		if (!empty($this->fk_product_lot)) {
			// Save incoming values before fetch overwrites them
			$target_wh     = $this->fk_entrepot;
			$target_note   = $this->note;
			$target_levels = array();
			for ($i = 1; $i <= 6; $i++) {
				$target_levels[$i] = $this->{'level'.$i.'_value'};
			}

			$existing = $this->fetchAnyByLot($this->fk_product_lot);
			if ($existing > 0) {
				// Update existing row with new values (including possibly new warehouse)
				$this->fk_entrepot = $target_wh;
				$this->note        = $target_note;
				for ($i = 1; $i <= 6; $i++) {
					$this->{'level'.$i.'_value'} = $target_levels[$i];
				}
				return $this->updateFull($user);
			} elseif ($existing == 0) {
				// Restore and create fresh
				$this->fk_entrepot = $target_wh;
				$this->note        = $target_note;
				for ($i = 1; $i <= 6; $i++) {
					$this->{'level'.$i.'_value'} = $target_levels[$i];
				}
				return $this->create($user);
			}
			return $existing;
		}

		// Non-serialized path — save incoming values before fetch overwrites them
		$target_note   = $this->note;
		$target_levels = array();
		for ($i = 1; $i <= 6; $i++) {
			$target_levels[$i] = $this->{'level'.$i.'_value'};
		}

		$existing = $this->fetchByProductWarehouseLot($this->fk_product, $this->fk_entrepot, null);

		$this->note = $target_note;
		for ($i = 1; $i <= 6; $i++) {
			$this->{'level'.$i.'_value'} = $target_levels[$i];
		}

		if ($existing > 0) {
			return $this->update($user);
		} elseif ($existing == 0) {
			return $this->create($user);
		}
		return $existing;
	}

	/**
	 * Full update — also writes fk_entrepot (used when a serial is reassigned to a new warehouse)
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function updateFull($user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " fk_entrepot = ".(int) $this->fk_entrepot.",";
		for ($i = 1; $i <= 6; $i++) {
			$val = $this->{'level'.$i.'_value'};
			$sql .= " level".$i."_value = ".($val !== null && $val !== '' ? "'".$this->db->escape($val)."'" : "NULL").",";
		}
		$sql .= " note = ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL").",";
		$sql .= " fk_user_modif = ".(int) $user->id;
		$sql .= " WHERE rowid = ".(int) $this->id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Get formatted location string for display
	 *
	 * @param  array $level_cfgs level_num => stdClass (with ->label) from BinlocWarehouseLevel
	 * @return string             e.g. "Row: A / Bay: 3 / Shelf: 2"
	 */
	public function getFormattedLocation($level_cfgs)
	{
		$parts = array();
		foreach ($level_cfgs as $num => $cfg) {
			$val = $this->{'level'.$num.'_value'};
			if ($val !== null && $val !== '') {
				$label = is_object($cfg) ? $cfg->label : (string) $cfg;
				$parts[] = $label.': '.$val;
			}
		}
		return implode(' / ', $parts);
	}
}
