<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    class/binlocwarehouselevel.class.php
 * \ingroup binloc
 * \brief   Business class for warehouse level configuration
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class BinlocWarehouseLevel
 *
 * Manages per-warehouse level definitions (e.g. "Row", "Bay", "Shelf").
 * Levels have stable identity (rowid) referenced by location values; display
 * order is the `position` column. Allowed values for datatype='list' live in
 * llx_binloc_level_options (see BinlocLevelOption).
 */
class BinlocWarehouseLevel extends CommonObject
{
	/** @var string */
	public $element = 'binlocwarehouselevel';

	/** @var string */
	public $table_element = 'binloc_warehouse_levels';

	/** @var int */
	public $fk_entrepot;

	/** @var string */
	public $label;

	/** @var string Input type: 'text' | 'number' | 'list' */
	public $datatype = 'text';

	/** @var int Display order */
	public $position = 0;

	/** @var int */
	public $active = 1;

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
	 * Create a level record
	 *
	 * @param  User $user User performing action
	 * @return int        >0 if OK (rowid), <0 if KO
	 */
	public function create($user)
	{
		$now = dol_now();

		$datatype = in_array($this->datatype, array('text', 'number', 'list'), true) ? $this->datatype : 'text';

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity, fk_entrepot, level_num, label, datatype, position, active, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (int) getEntity('stock');
		$sql .= ", ".(int) $this->fk_entrepot;
		$sql .= ", ".(int) $this->position; // level_num kept informational only
		$sql .= ", '".$this->db->escape($this->label)."'";
		$sql .= ", '".$this->db->escape($datatype)."'";
		$sql .= ", ".(int) $this->position;
		$sql .= ", ".(int) $this->active;
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
	 * Fetch all level definitions for a warehouse
	 *
	 * Returns a map keyed by level rowid, ordered by position, where each value
	 * is an stdClass with:
	 *   - id       (int, level rowid)
	 *   - label    (string)
	 *   - datatype ('text' | 'number' | 'list')
	 *   - position (int)
	 *   - active   (int)
	 *   - options  (array of stdClass {id, value, position, active}, ALL options
	 *               including inactive ones — renderers decide what to show)
	 *
	 * @param  int  $fk_entrepot      Warehouse ID
	 * @param  bool $include_inactive Include deactivated levels
	 * @return array                  rowid => stdClass map, or empty array
	 */
	public function fetchByWarehouse($fk_entrepot, $include_inactive = false)
	{
		$result = $this->fetchByWarehouses(array((int) $fk_entrepot), $include_inactive);
		return isset($result[(int) $fk_entrepot]) ? $result[(int) $fk_entrepot] : array();
	}

	/**
	 * Fetch level definitions for several warehouses in one query
	 *
	 * @param  int[] $fk_entrepots     Warehouse IDs
	 * @param  bool  $include_inactive Include deactivated levels
	 * @return array                   fk_entrepot => (rowid => stdClass) map
	 */
	public function fetchByWarehouses($fk_entrepots, $include_inactive = false)
	{
		$out = array();
		$ids = array_filter(array_map('intval', (array) $fk_entrepots));
		if (empty($ids)) {
			return $out;
		}
		foreach ($ids as $id) {
			$out[$id] = array();
		}

		$sql = "SELECT w.rowid, w.fk_entrepot, w.label, w.datatype, w.position, w.active,";
		$sql .= " o.rowid as opt_id, o.value as opt_value, o.position as opt_position, o.active as opt_active";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as w";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."binloc_level_options as o ON o.fk_level = w.rowid";
		$sql .= " WHERE w.fk_entrepot IN (".implode(',', $ids).")";
		$sql .= " AND w.entity IN (".getEntity('stock').")";
		if (!$include_inactive) {
			$sql .= " AND w.active = 1";
		}
		$sql .= " ORDER BY w.position ASC, w.rowid ASC, o.position ASC, o.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$wh = (int) $obj->fk_entrepot;
			$lid = (int) $obj->rowid;
			if (!isset($out[$wh][$lid])) {
				$cfg = new stdClass();
				$cfg->id       = $lid;
				$cfg->label    = $obj->label;
				$cfg->datatype = in_array($obj->datatype, array('text', 'number', 'list'), true) ? $obj->datatype : 'text';
				$cfg->position = (int) $obj->position;
				$cfg->active   = (int) $obj->active;
				$cfg->options  = array();
				$out[$wh][$lid] = $cfg;
			}
			if ($obj->opt_id) {
				$opt = new stdClass();
				$opt->id       = (int) $obj->opt_id;
				$opt->value    = $obj->opt_value;
				$opt->position = (int) $obj->opt_position;
				$opt->active   = (int) $obj->opt_active;
				$out[$wh][$lid]->options[] = $opt;
			}
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Fetch just the label map (compatibility helper)
	 *
	 * @param  int   $fk_entrepot Warehouse ID
	 * @return array              level rowid => label string map, position order
	 */
	public function fetchLabelsByWarehouse($fk_entrepot)
	{
		$labels = array();
		foreach ($this->fetchByWarehouse($fk_entrepot) as $id => $cfg) {
			$labels[$id] = $cfg->label;
		}
		return $labels;
	}

	/**
	 * Get the number of active levels for a warehouse
	 *
	 * @param  int $fk_entrepot Warehouse ID
	 * @return int              Number of active levels
	 */
	public function getMaxLevel($fk_entrepot)
	{
		return count($this->fetchByWarehouse($fk_entrepot));
	}

	/**
	 * Delete all level definitions for a warehouse (uninstall/purge only —
	 * normal edits go through applyWarehouseLevels to preserve identity)
	 *
	 * @param  int $fk_entrepot Warehouse ID
	 * @return int              >0 if OK, <0 if KO
	 */
	public function deleteByWarehouse($fk_entrepot)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_entrepot = ".(int) $fk_entrepot;
		$sql .= " AND entity IN (".getEntity('stock').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Apply a full set of levels for a warehouse, preserving identity.
	 *
	 * Each row of $rows: array(
	 *   'id'       => existing level rowid, or 0 for a new level,
	 *   'label'    => string,
	 *   'datatype' => 'text'|'number'|'list',
	 *   'position' => int display order,
	 * )
	 * Existing levels missing from $rows are soft-deactivated when still
	 * referenced by location values, hard-deleted otherwise. Options survive
	 * because level rowids never change.
	 *
	 * @param  int   $fk_entrepot Warehouse ID
	 * @param  array $rows        Level rows as described above
	 * @param  User  $user        User performing action
	 * @return int                >0 if OK, <0 if KO
	 */
	public function applyWarehouseLevels($fk_entrepot, $rows, $user)
	{
		$existing = $this->fetchByWarehouse($fk_entrepot, true);

		$this->db->begin();

		$kept = array();
		foreach ($rows as $row) {
			$id       = isset($row['id']) ? (int) $row['id'] : 0;
			$label    = isset($row['label']) ? trim($row['label']) : '';
			$datatype = (isset($row['datatype']) && in_array($row['datatype'], array('text', 'number', 'list'), true)) ? $row['datatype'] : 'text';
			$position = isset($row['position']) ? (int) $row['position'] : 0;

			if ($label === '') {
				continue;
			}

			if ($id > 0 && isset($existing[$id])) {
				$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
				$sql .= " label = '".$this->db->escape($label)."'";
				$sql .= ", datatype = '".$this->db->escape($datatype)."'";
				$sql .= ", position = ".$position;
				$sql .= ", level_num = ".$position;
				$sql .= ", active = 1";
				$sql .= ", fk_user_modif = ".(int) $user->id;
				$sql .= " WHERE rowid = ".$id;
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					$this->db->rollback();
					return -1;
				}
				$kept[$id] = true;
			} else {
				$this->fk_entrepot = $fk_entrepot;
				$this->label       = $label;
				$this->datatype    = $datatype;
				$this->position    = $position;
				$this->active      = 1;
				$this->id          = 0;
				$result = $this->create($user);
				if ($result < 0) {
					$this->db->rollback();
					return -1;
				}
				$kept[$result] = true;
			}
		}

		// Levels removed from the submitted set: deactivate when referenced,
		// delete when not (options cascade with the level)
		foreach ($existing as $id => $cfg) {
			if (isset($kept[$id])) {
				continue;
			}
			$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."binloc_location_value WHERE fk_level = ".(int) $id;
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);

			if ((int) $obj->nb > 0) {
				$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET active = 0, fk_user_modif = ".(int) $user->id." WHERE rowid = ".(int) $id;
			} else {
				$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int) $id;
			}
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Copy level configuration (including list options) to another warehouse.
	 * Refuses when the target already has levels, so existing references are
	 * never silently re-labeled.
	 *
	 * @param  int  $source_fk_entrepot Source warehouse ID
	 * @param  int  $target_fk_entrepot Target warehouse ID
	 * @param  User $user               User performing action
	 * @return int                       >0 if OK, <0 if KO
	 */
	public function copyFromWarehouse($source_fk_entrepot, $target_fk_entrepot, $user)
	{
		$source_levels = $this->fetchByWarehouse($source_fk_entrepot);
		if (empty($source_levels)) {
			$this->error = 'No levels configured on source warehouse';
			return -1;
		}

		if (count($this->fetchByWarehouse($target_fk_entrepot, true)) > 0) {
			$this->error = 'TargetWarehouseHasLevels';
			return -2;
		}

		dol_include_once('/binloc/class/binloclevaloption.class.php');
		$optionHandler = new BinlocLevelOption($this->db);

		$this->db->begin();

		foreach ($source_levels as $cfg) {
			$this->fk_entrepot = $target_fk_entrepot;
			$this->label       = $cfg->label;
			$this->datatype    = $cfg->datatype;
			$this->position    = $cfg->position;
			$this->active      = 1;
			$this->id          = 0;
			$new_level_id = $this->create($user);
			if ($new_level_id < 0) {
				$this->db->rollback();
				return -1;
			}

			foreach ($cfg->options as $opt) {
				if (!$opt->active) {
					continue;
				}
				$result = $optionHandler->create($new_level_id, $opt->value, $opt->position, $user);
				if ($result < 0) {
					$this->error = $optionHandler->error;
					$this->db->rollback();
					return -1;
				}
			}
		}

		$this->db->commit();
		return 1;
	}
}
