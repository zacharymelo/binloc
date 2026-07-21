<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    class/binloclevaloption.class.php
 * \ingroup binloc
 * \brief   Business class for level list options
 */

/**
 * Class BinlocLevelOption
 *
 * CRUD for the predefined values of a datatype='list' level. Location values
 * reference options by rowid, so rename() propagates to every assignment
 * automatically and delete() must be blocked while referenced (deactivate
 * instead — inactive options stay resolvable for display but are hidden from
 * new input).
 */
class BinlocLevelOption
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

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
	 * Fetch options of a level, position order
	 *
	 * @param  int  $fk_level         Level rowid
	 * @param  bool $include_inactive Include deactivated options
	 * @return array                  Array of stdClass {id, value, position, active}
	 */
	public function fetchByLevel($fk_level, $include_inactive = false)
	{
		$results = array();

		$sql = "SELECT rowid, value, position, active FROM ".MAIN_DB_PREFIX."binloc_level_options";
		$sql .= " WHERE fk_level = ".(int) $fk_level;
		if (!$include_inactive) {
			$sql .= " AND active = 1";
		}
		$sql .= " ORDER BY position ASC, rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $results;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$opt = new stdClass();
			$opt->id       = (int) $obj->rowid;
			$opt->value    = $obj->value;
			$opt->position = (int) $obj->position;
			$opt->active   = (int) $obj->active;
			$results[] = $opt;
		}
		$this->db->free($resql);

		return $results;
	}

	/**
	 * Create an option
	 *
	 * @param  int    $fk_level Level rowid
	 * @param  string $value    Option value
	 * @param  int    $position Display order
	 * @param  User   $user     User performing action
	 * @return int              >0 if OK (rowid), -2 on duplicate, <0 if KO
	 */
	public function create($fk_level, $value, $position, $user)
	{
		$value = trim($value);
		if ($value === '' || dol_strlen($value) > 64) {
			$this->error = 'InvalidOptionValue';
			return -1;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."binloc_level_options (";
		$sql .= "entity, fk_level, value, position, active, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (int) getEntity('stock');
		$sql .= ", ".(int) $fk_level;
		$sql .= ", '".$this->db->escape($value)."'";
		$sql .= ", ".(int) $position;
		$sql .= ", 1";
		$sql .= ", '".$this->db->idate(dol_now())."'";
		$sql .= ", ".(int) $user->id;
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			if ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->error = 'OptionAlreadyExists';
				return -2;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.'binloc_level_options');
	}

	/**
	 * Rename an option in place. Every location value referencing it follows
	 * automatically — this is the v2 replacement for editing the CSV.
	 *
	 * @param  int    $id        Option rowid
	 * @param  string $new_value New value
	 * @param  User   $user      User performing action
	 * @return int               >0 if OK, -2 on duplicate, <0 if KO
	 */
	public function rename($id, $new_value, $user)
	{
		$new_value = trim($new_value);
		if ($new_value === '' || dol_strlen($new_value) > 64) {
			$this->error = 'InvalidOptionValue';
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."binloc_level_options";
		$sql .= " SET value = '".$this->db->escape($new_value)."', fk_user_modif = ".(int) $user->id;
		$sql .= " WHERE rowid = ".(int) $id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			if ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->error = 'OptionAlreadyExists';
				return -2;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Change display order
	 *
	 * @param  int  $id       Option rowid
	 * @param  int  $position New position
	 * @param  User $user     User performing action
	 * @return int            >0 if OK, <0 if KO
	 */
	public function reorder($id, $position, $user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."binloc_level_options";
		$sql .= " SET position = ".(int) $position.", fk_user_modif = ".(int) $user->id;
		$sql .= " WHERE rowid = ".(int) $id;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Activate/deactivate an option. Inactive options are hidden from new input
	 * but keep resolving for existing assignments.
	 *
	 * @param  int  $id     Option rowid
	 * @param  int  $active 0 or 1
	 * @param  User $user   User performing action
	 * @return int          >0 if OK, <0 if KO
	 */
	public function setActive($id, $active, $user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."binloc_level_options";
		$sql .= " SET active = ".($active ? 1 : 0).", fk_user_modif = ".(int) $user->id;
		$sql .= " WHERE rowid = ".(int) $id;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Number of location values referencing this option
	 *
	 * @param  int $id Option rowid
	 * @return int     Count, or -1 on error
	 */
	public function countReferences($id)
	{
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."binloc_location_value WHERE fk_option = ".(int) $id;
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
	 * Delete an option only when nothing references it
	 *
	 * @param  int $id Option rowid
	 * @return int     >0 if deleted, -2 if still referenced (deactivate instead), <0 if KO
	 */
	public function deleteIfUnreferenced($id)
	{
		$refs = $this->countReferences($id);
		if ($refs < 0) {
			return -1;
		}
		if ($refs > 0) {
			$this->error = 'OptionInUse';
			return -2;
		}

		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."binloc_level_options WHERE rowid = ".(int) $id)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
