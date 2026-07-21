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
 * Manages product location assignments within warehouses.
 * One record per product per warehouse for non-serialized products.
 * One record per product-lot per warehouse for serialized/batch products.
 *
 * fk_product_lot is always an int; 0 means "no lot". Level values live in
 * llx_binloc_location_value as $this->values, a map keyed by level rowid:
 *   fk_level => stdClass { fk_option (int|null), value (string|null), display (string) }
 * List levels reference an option rowid (renames propagate automatically);
 * text/number levels store the raw string.
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

	/** @var int Lot/serial ID, 0 for non-serialized products */
	public $fk_product_lot = 0;

	/** @var array fk_level => stdClass {fk_option, value, display} */
	public $values = array();

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

	// ------------------------------------------------------------------
	// Value handling
	// ------------------------------------------------------------------

	/**
	 * Set the value of one level from raw user input, validating against the
	 * level's configuration.
	 *
	 * For list levels $raw is the selected option rowid; for text/number
	 * levels it is the entered string. An empty $raw clears the level.
	 *
	 * @param  stdClass $cfg Level config from BinlocWarehouseLevel::fetchByWarehouse
	 * @param  mixed    $raw Raw input
	 * @return int           1 if OK, -1 on validation error ($this->error set)
	 */
	public function setRawValue($cfg, $raw)
	{
		$level_id = (int) $cfg->id;

		if ($raw === null || $raw === '' || ($cfg->datatype === 'list' && (int) $raw === 0)) {
			unset($this->values[$level_id]);
			return 1;
		}

		$entry = new stdClass();
		$entry->fk_option = null;
		$entry->value = null;

		if ($cfg->datatype === 'list') {
			$opt_id = (int) $raw;
			$match = null;
			foreach ($cfg->options as $opt) {
				if ((int) $opt->id === $opt_id) {
					$match = $opt;
					break;
				}
			}
			if (!$match) {
				$this->error = 'InvalidOptionForLevel';
				return -1;
			}
			$entry->fk_option = $opt_id;
			$entry->display = $match->value;
		} else {
			$val = trim((string) $raw);
			if (dol_strlen($val) > 64) {
				$this->error = 'ValueTooLong';
				return -1;
			}
			if ($cfg->datatype === 'number' && !is_numeric($val)) {
				$this->error = 'ValueNotNumeric';
				return -1;
			}
			$entry->value = $val;
			$entry->display = $val;
		}

		$this->values[$level_id] = $entry;
		return 1;
	}

	/**
	 * Populate $this->values from a request-style array of raw inputs.
	 *
	 * @param  array $level_cfgs Level configs keyed by rowid (fetchByWarehouse output)
	 * @param  array $raw_values level rowid => raw input (option id or string)
	 * @return int               1 if OK, -1 on first validation error
	 */
	public function setRawValues($level_cfgs, $raw_values)
	{
		$this->values = array();
		foreach ($level_cfgs as $id => $cfg) {
			$raw = isset($raw_values[$id]) ? $raw_values[$id] : '';
			if ($this->setRawValue($cfg, $raw) < 0) {
				return -1;
			}
		}
		return 1;
	}

	/**
	 * True when at least one level has a value
	 *
	 * @return bool
	 */
	public function hasValues()
	{
		return !empty($this->values);
	}

	// ------------------------------------------------------------------
	// Lookups (return rowid only — never hydrate $this)
	// ------------------------------------------------------------------

	/**
	 * Find the rowid of the assignment for (product, warehouse, lot)
	 *
	 * @param  int $fk_product     Product ID
	 * @param  int $fk_entrepot    Warehouse ID
	 * @param  int $fk_product_lot Lot ID (0 = no lot)
	 * @return int                 rowid, 0 if not found, -1 on error
	 */
	public function findRowId($fk_product, $fk_entrepot, $fk_product_lot = 0)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product = ".(int) $fk_product;
		$sql .= " AND fk_entrepot = ".(int) $fk_entrepot;
		$sql .= " AND fk_product_lot = ".(int) $fk_product_lot;
		$sql .= " AND entity IN (".getEntity('stock').")";

		return $this->fetchSingleId($sql);
	}

	/**
	 * Find the rowid of a lot's assignment, in any warehouse
	 * (one serial = one location anywhere)
	 *
	 * @param  int $fk_product_lot Lot/serial ID
	 * @return int                 rowid, 0 if not found, -1 on error
	 */
	public function findRowIdByLot($fk_product_lot)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product_lot = ".(int) $fk_product_lot;
		$sql .= " AND entity IN (".getEntity('stock').")";
		$sql .= " LIMIT 1";

		return $this->fetchSingleId($sql);
	}

	/**
	 * Run a rowid lookup query
	 *
	 * @param  string $sql Query selecting a single rowid
	 * @return int         rowid, 0 if not found, -1 on error
	 */
	private function fetchSingleId($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	// ------------------------------------------------------------------
	// CRUD
	// ------------------------------------------------------------------

	/**
	 * Create an assignment (header + level values) in one transaction.
	 *
	 * Uniqueness of (entity, product, warehouse, lot) is enforced by the DB
	 * unique index; a duplicate insert returns -2 so callers can retry as an
	 * update (createOrUpdate does this automatically).
	 *
	 * @param  User $user User performing action
	 * @return int        rowid if OK, -2 on duplicate, <0 if KO
	 */
	public function create($user)
	{
		if ((int) $this->fk_product <= 0 || (int) $this->fk_entrepot <= 0) {
			$this->error = 'MissingProductOrWarehouse';
			return -1;
		}

		$now = dol_now();

		$this->db->begin();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity, fk_product, fk_entrepot, fk_product_lot, note, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (int) getEntity('stock');
		$sql .= ", ".(int) $this->fk_product;
		$sql .= ", ".(int) $this->fk_entrepot;
		$sql .= ", ".(int) $this->fk_product_lot;
		$sql .= ", ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", ".(int) $user->id;
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$duplicate = ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS');
			$this->error = $duplicate ? 'Duplicate' : $this->db->lasterror();
			$this->db->rollback();
			return $duplicate ? -2 : -1;
		}

		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->fk_user_creat = $user->id;

		if ($this->writeValues() < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $this->id;
	}

	/**
	 * Update an assignment (header + level values) in one transaction.
	 * Always writes fk_entrepot, so moving a serial to another warehouse is a
	 * plain update of its single row.
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function update($user)
	{
		if ((int) $this->id <= 0) {
			$this->error = 'MissingId';
			return -1;
		}

		$this->db->begin();

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " fk_entrepot = ".(int) $this->fk_entrepot;
		$sql .= ", note = ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= ", fk_user_modif = ".(int) $user->id;
		$sql .= " WHERE rowid = ".(int) $this->id;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		if ($this->writeValues() < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Replace the level value child rows with $this->values
	 *
	 * @return int >0 if OK, <0 if KO
	 */
	private function writeValues()
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."binloc_location_value WHERE fk_location = ".(int) $this->id;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		foreach ($this->values as $fk_level => $entry) {
			if (empty($entry->fk_option) && ($entry->value === null || $entry->value === '')) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."binloc_location_value (fk_location, fk_level, fk_option, value)";
			$sql .= " VALUES (";
			$sql .= (int) $this->id;
			$sql .= ", ".(int) $fk_level;
			$sql .= ", ".(!empty($entry->fk_option) ? (int) $entry->fk_option : "NULL");
			$sql .= ", ".($entry->value !== null && $entry->value !== '' ? "'".$this->db->escape($entry->value)."'" : "NULL");
			$sql .= ")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Create or update an assignment (upsert)
	 *
	 * - Serialized (fk_product_lot > 0): the lot's existing row anywhere is
	 *   updated (including a warehouse move) — one serial, one location.
	 * - Non-serialized: matches (product, warehouse, lot 0).
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK, <0 if KO
	 */
	public function createOrUpdate($user)
	{
		if ((int) $this->fk_product_lot > 0) {
			$existing = $this->findRowIdByLot($this->fk_product_lot);
		} else {
			$existing = $this->findRowId($this->fk_product, $this->fk_entrepot, 0);
		}
		if ($existing < 0) {
			return -1;
		}

		if ($existing > 0) {
			$this->id = $existing;
			return $this->update($user);
		}

		$result = $this->create($user);
		if ($result == -2) {
			// Lost a create race — the row exists now, retry once as update
			$existing = ((int) $this->fk_product_lot > 0)
				? $this->findRowIdByLot($this->fk_product_lot)
				: $this->findRowId($this->fk_product, $this->fk_entrepot, 0);
			if ($existing > 0) {
				$this->id = $existing;
				return $this->update($user);
			}
			return -1;
		}
		return $result;
	}

	/**
	 * Fetch an assignment by ID, including its level values
	 *
	 * @param  int $id Record ID
	 * @return int     >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, fk_product, fk_entrepot, fk_product_lot,";
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

		$this->id             = (int) $obj->rowid;
		$this->entity         = (int) $obj->entity;
		$this->fk_product     = (int) $obj->fk_product;
		$this->fk_entrepot    = (int) $obj->fk_entrepot;
		$this->fk_product_lot = (int) $obj->fk_product_lot;
		$this->note           = $obj->note;
		$this->fk_user_creat  = (int) $obj->fk_user_creat;
		$this->fk_user_modif  = $obj->fk_user_modif ? (int) $obj->fk_user_modif : null;

		$this->values = $this->loadValues($this->id);

		return 1;
	}

	/**
	 * Load the level values of one assignment
	 *
	 * @param  int   $fk_location Assignment rowid
	 * @return array              fk_level => stdClass {fk_option, value, display}
	 */
	private function loadValues($fk_location)
	{
		$values = array();

		$sql = "SELECT v.fk_level, v.fk_option, v.value, o.value as option_value";
		$sql .= " FROM ".MAIN_DB_PREFIX."binloc_location_value as v";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_level_options as o ON o.rowid = v.fk_option";
		$sql .= " WHERE v.fk_location = ".(int) $fk_location;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $values;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$entry = new stdClass();
			$entry->fk_option = $obj->fk_option ? (int) $obj->fk_option : null;
			$entry->value     = $obj->value;
			$entry->display   = ($obj->fk_option ? $obj->option_value : $obj->value);
			$values[(int) $obj->fk_level] = $entry;
		}
		$this->db->free($resql);

		return $values;
	}

	/**
	 * Fetch by product + warehouse + lot combination
	 *
	 * @param  int $fk_product     Product ID
	 * @param  int $fk_entrepot    Warehouse ID
	 * @param  int $fk_product_lot Lot/serial ID (0 for non-serialized)
	 * @return int                 >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetchByProductWarehouseLot($fk_product, $fk_entrepot, $fk_product_lot = 0)
	{
		$rowid = $this->findRowId($fk_product, $fk_entrepot, (int) $fk_product_lot);
		if ($rowid <= 0) {
			return $rowid;
		}
		return $this->fetch($rowid);
	}

	/**
	 * Fetch the NON-LOT assignment of a product in a warehouse.
	 *
	 * Lot-specific rows are deliberately excluded: serialized products are
	 * addressed per lot via fetchByProductWarehouseLot()/fetchAnyByLot().
	 *
	 * @param  int $fk_product   Product ID
	 * @param  int $fk_entrepot  Warehouse ID
	 * @return int               >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetchByProductWarehouse($fk_product, $fk_entrepot)
	{
		return $this->fetchByProductWarehouseLot($fk_product, $fk_entrepot, 0);
	}

	/**
	 * Fetch a lot's assignment, in any warehouse
	 *
	 * @param  int $fk_product_lot Lot/serial ID
	 * @return int                 >0 if OK and loaded, 0 if not found, <0 if KO
	 */
	public function fetchAnyByLot($fk_product_lot)
	{
		$rowid = $this->findRowIdByLot($fk_product_lot);
		if ($rowid <= 0) {
			return $rowid;
		}
		return $this->fetch($rowid);
	}

	/**
	 * SQL fragment: formatted location string for a header row aliased "pl".
	 * Values of inactive levels are still shown (data is never hidden by a
	 * config change), ordered by level position.
	 *
	 * @return string
	 */
	private function formattedLocationSubquery()
	{
		$sql = "(SELECT GROUP_CONCAT(CONCAT(w.label, ': ', COALESCE(o.value, v.value))";
		$sql .= " ORDER BY w.position ASC, w.rowid ASC SEPARATOR ' / ')";
		$sql .= " FROM ".MAIN_DB_PREFIX."binloc_location_value as v";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."binloc_warehouse_levels as w ON w.rowid = v.fk_level";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_level_options as o ON o.rowid = v.fk_option";
		$sql .= " WHERE v.fk_location = pl.rowid)";
		return $sql;
	}

	/**
	 * Attach ->values to a list of result rows (one IN query, keyed by rowid)
	 *
	 * @param  array $rows Rows with ->rowid, modified in place
	 * @return void
	 */
	public function loadValuesForRows($rows)
	{
		$ids = array();
		$byId = array();
		foreach ($rows as $row) {
			$ids[] = (int) $row->rowid;
			$byId[(int) $row->rowid] = $row;
			$row->values = array();
		}
		if (empty($ids)) {
			return;
		}

		$sql = "SELECT v.fk_location, v.fk_level, v.fk_option, v.value, o.value as option_value";
		$sql .= " FROM ".MAIN_DB_PREFIX."binloc_location_value as v";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_level_options as o ON o.rowid = v.fk_option";
		$sql .= " WHERE v.fk_location IN (".implode(',', $ids).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$entry = new stdClass();
			$entry->fk_option = $obj->fk_option ? (int) $obj->fk_option : null;
			$entry->value     = $obj->value;
			$entry->display   = ($obj->fk_option ? $obj->option_value : $obj->value);
			$byId[(int) $obj->fk_location]->values[(int) $obj->fk_level] = $entry;
		}
		$this->db->free($resql);
	}

	/**
	 * Fetch all location records for a product (across warehouses and lots)
	 *
	 * Each row: rowid, fk_entrepot, fk_product_lot, lot_batch, warehouse_ref,
	 * warehouse_label, stock, note, location (formatted string), values map.
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
		$sql .= " pl.note,";
		$sql .= " IFNULL(ps.reel, 0) as stock,";
		$sql .= " ".$this->formattedLocationSubquery()." as location";
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
			$row->fk_product_lot  = (int) $obj->fk_product_lot;
			$row->lot_batch       = $obj->lot_batch;
			$row->warehouse_ref   = $obj->warehouse_ref;
			$row->warehouse_label = $obj->warehouse_label;
			$row->stock           = (float) $obj->stock;
			$row->note            = $obj->note;
			$row->location        = (string) $obj->location;
			$results[] = $row;
		}
		$this->db->free($resql);

		$this->loadValuesForRows($results);

		return $results;
	}

	/**
	 * SQL fragment restricting header rows (aliased "pl") to those matching
	 * per-level bin filters.
	 *
	 * Each filter: array('fk_level' => int) plus either 'fk_option' => int
	 * (exact match, list levels) or 'value' => string (partial match,
	 * text/number levels). Filters on different levels AND together.
	 *
	 * @param  array  $level_filters Filters as described above
	 * @return string                SQL fragment starting with " AND", or ''
	 */
	private function levelFilterSql($level_filters)
	{
		$sql = '';
		foreach ((array) $level_filters as $filter) {
			$fk_level = isset($filter['fk_level']) ? (int) $filter['fk_level'] : 0;
			if ($fk_level <= 0) {
				continue;
			}
			if (!empty($filter['fk_option'])) {
				$cond = "v.fk_option = ".(int) $filter['fk_option'];
			} elseif (isset($filter['value']) && $filter['value'] !== '') {
				$cond = "v.value LIKE '%".$this->db->escape($filter['value'])."%'";
			} else {
				continue;
			}
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."binloc_location_value as v";
			$sql .= " WHERE v.fk_location = pl.rowid AND v.fk_level = ".$fk_level." AND ".$cond.")";
		}
		return $sql;
	}

	/**
	 * Fetch all products with locations in a warehouse
	 *
	 * @param  int    $fk_entrepot   Warehouse ID
	 * @param  string $search        Optional product ref/label/batch search filter
	 * @param  string $sortfield     Sort field
	 * @param  string $sortorder     Sort order (ASC/DESC)
	 * @param  int    $limit         Max rows
	 * @param  int    $offset        Offset
	 * @param  array  $level_filters Optional bin filters (see levelFilterSql)
	 * @return array                 Array of result objects (see fetchAllByProduct)
	 */
	public function fetchAllByWarehouse($fk_entrepot, $search = '', $sortfield = 'p.ref', $sortorder = 'ASC', $limit = 0, $offset = 0, $level_filters = array())
	{
		$results = array();

		$sql = "SELECT pl.rowid, pl.fk_product, pl.fk_product_lot,";
		$sql .= " p.ref as product_ref, p.label as product_label,";
		$sql .= " lot.batch as lot_batch,";
		$sql .= " pl.note,";
		$sql .= " IFNULL(ps.reel, 0) as stock,";
		$sql .= " ".$this->formattedLocationSubquery()." as location";
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

		$sql .= $this->levelFilterSql($level_filters);

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
			$row->fk_product_lot = (int) $obj->fk_product_lot;
			$row->lot_batch      = $obj->lot_batch;
			$row->product_ref    = $obj->product_ref;
			$row->product_label  = $obj->product_label;
			$row->stock          = (float) $obj->stock;
			$row->note           = $obj->note;
			$row->location       = (string) $obj->location;
			$results[] = $row;
		}
		$this->db->free($resql);

		$this->loadValuesForRows($results);

		return $results;
	}

	/**
	 * Count products with locations in a warehouse
	 *
	 * @param  int    $fk_entrepot   Warehouse ID
	 * @param  string $search        Optional search filter
	 * @param  array  $level_filters Optional bin filters (see levelFilterSql)
	 * @return int                    Count or -1 on error
	 */
	public function countByWarehouse($fk_entrepot, $search = '', $level_filters = array())
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

		$sql .= $this->levelFilterSql($level_filters);

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
	 * Delete an assignment (child value rows cascade)
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
	 * Delete all assignments of a lot (used by the PRODUCTLOT_DELETE trigger —
	 * there is no DB cascade because fk_product_lot 0 has no parent row)
	 *
	 * @param  int $fk_product_lot Lot/serial ID
	 * @return int                 >0 if OK, <0 if KO
	 */
	public function deleteByLot($fk_product_lot)
	{
		if ((int) $fk_product_lot <= 0) {
			return 0;
		}
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_product_lot = ".(int) $fk_product_lot;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Delete the NON-LOT location record of a product/warehouse when stock
	 * drops to zero. Lot-specific rows are left alone — serials are cleared
	 * individually by the stock-movement trigger.
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
		$sql .= " AND fk_product_lot = 0";
		$sql .= " AND entity IN (".getEntity('stock').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return $this->db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Get formatted location string for display
	 *
	 * @param  array $level_cfgs Level configs keyed by rowid (fetchByWarehouse output)
	 * @return string             e.g. "Row: A / Bay: 3 / Shelf: 2"
	 */
	public function getFormattedLocation($level_cfgs)
	{
		$parts = array();
		foreach ($level_cfgs as $id => $cfg) {
			if (isset($this->values[$id]) && $this->values[$id]->display !== null && $this->values[$id]->display !== '') {
				$label = is_object($cfg) ? $cfg->label : (string) $cfg;
				$parts[] = $label.': '.$this->values[$id]->display;
			}
		}
		return implode(' / ', $parts);
	}
}
