<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    class/binlocmigration.class.php
 * \ingroup binloc
 * \brief   Versioned schema/data migration runner for Binloc v2
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * Class BinlocMigration
 *
 * Migrates a v1 install (positional level1..6 text columns, CSV list_values)
 * to the v2 normalized schema (location_value child rows, level_options by
 * rowid, lot-0 sentinel with a real unique index).
 *
 * Progress is tracked per step in the BINLOC_DB_VERSION constant so a failed
 * or interrupted run resumes where it stopped. Failures are recorded in
 * BINLOC_DB_MIGRATION_ERROR and surfaced on the admin setup page — never
 * swallowed. The destructive step (dropping legacy columns) runs last, only
 * after the verification step has passed.
 */
class BinlocMigration
{
	const TARGET_DB_VERSION = '2.0.0-9';

	/** @var DoliDB */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var array Accumulated migration report (counts etc.), persisted as JSON */
	protected $report = array();

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
	 * Ordered migration steps: version => method name
	 *
	 * @return array
	 */
	protected function getSteps()
	{
		return array(
			'2.0.0-1' => 'stepPreflight',
			'2.0.0-2' => 'stepCreateStructures',
			'2.0.0-3' => 'stepPopulatePosition',
			'2.0.0-4' => 'stepExplodeCsvOptions',
			'2.0.0-5' => 'stepDedupeHeaders',
			'2.0.0-6' => 'stepLotConversion',
			'2.0.0-7' => 'stepExplodeLevelValues',
			'2.0.0-8' => 'stepVerify',
			'2.0.0-9' => 'stepDropLegacyColumns',
		);
	}

	/**
	 * Run all pending migration steps
	 *
	 * @return int 1 if up to date (or brought up to date), -1 on failure
	 */
	public function run()
	{
		$current = $this->getDbVersion();

		if (version_compare($current ?: '0', self::TARGET_DB_VERSION, '>=')) {
			return 1;
		}

		// Fresh install: tables were just created from the v2 sql files, there is
		// no legacy column and no stored version — stamp target and skip all steps.
		if ($current === '' && !$this->columnExists('binloc_product_location', 'level1_value')) {
			$this->setDbVersion(self::TARGET_DB_VERSION);
			$this->clearError();
			return 1;
		}

		$this->loadReport();

		foreach ($this->getSteps() as $version => $method) {
			if (version_compare($version, $current ?: '0', '<=')) {
				continue;
			}

			dol_syslog('BinlocMigration: running step '.$version.' ('.$method.')', LOG_NOTICE);
			$result = $this->$method();

			if ($result < 0) {
				$msg = $version.': '.($this->error ?: 'unknown error');
				dolibarr_set_const($this->db, 'BINLOC_DB_MIGRATION_ERROR', $msg, 'chaine', 0, '', 0);
				$this->saveReport();
				dol_syslog('BinlocMigration: FAILED at '.$msg, LOG_ERR);
				return -1;
			}

			$this->setDbVersion($version);
			$current = $version;
		}

		$this->clearError();
		$this->saveReport();
		dol_syslog('BinlocMigration: schema up to date at '.self::TARGET_DB_VERSION, LOG_NOTICE);
		return 1;
	}

	/**
	 * Migration status for the admin banner
	 *
	 * @return stdClass {state: 'ok'|'pending'|'failed', version, target, error, report}
	 */
	public function getStatus()
	{
		$status = new stdClass();
		$status->version = $this->getDbVersion();
		$status->target  = self::TARGET_DB_VERSION;
		$status->error   = dolibarr_get_const($this->db, 'BINLOC_DB_MIGRATION_ERROR', 0);
		$report = dolibarr_get_const($this->db, 'BINLOC_DB_MIGRATION_REPORT', 0);
		$status->report  = $report ? json_decode($report, true) : array();

		if ($status->error) {
			$status->state = 'failed';
		} elseif (version_compare($status->version ?: '0', self::TARGET_DB_VERSION, '>=')) {
			$status->state = 'ok';
		} else {
			$status->state = 'pending';
		}

		return $status;
	}

	// ------------------------------------------------------------------
	// Steps
	// ------------------------------------------------------------------

	/**
	 * 2.0.0-1: Preflight repair.
	 * Ensures pre-1.5 columns exist, removes referential orphans that would
	 * block FK creation, recreates v1 FKs that silently failed to install,
	 * and records the baseline counts used by the verification step.
	 *
	 * @return int
	 */
	protected function stepPreflight()
	{
		$pl = MAIN_DB_PREFIX.'binloc_product_location';
		$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';

		// Very old installs (<1.5) may lack these columns entirely
		if (!$this->addColumnIfMissing('binloc_product_location', 'fk_product_lot', 'INTEGER DEFAULT NULL AFTER fk_entrepot')) {
			return -1;
		}
		if (!$this->addColumnIfMissing('binloc_warehouse_levels', 'datatype', "VARCHAR(16) NOT NULL DEFAULT 'text' AFTER label")) {
			return -1;
		}
		if (!$this->addColumnIfMissing('binloc_warehouse_levels', 'list_values', 'VARCHAR(1024) DEFAULT NULL AFTER datatype')) {
			return -1;
		}

		// Remove rows whose parents are gone (installs where FK creation failed)
		$orphans = 0;
		$queries = array(
			"DELETE pl FROM ".$pl." pl LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product WHERE p.rowid IS NULL",
			"DELETE pl FROM ".$pl." pl LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = pl.fk_entrepot WHERE e.rowid IS NULL",
			"DELETE pl FROM ".$pl." pl LEFT JOIN ".MAIN_DB_PREFIX."product_lot l ON l.rowid = pl.fk_product_lot WHERE pl.fk_product_lot IS NOT NULL AND pl.fk_product_lot > 0 AND l.rowid IS NULL",
			"DELETE wl FROM ".$wl." wl LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = wl.fk_entrepot WHERE e.rowid IS NULL",
		);
		foreach ($queries as $sql) {
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$orphans += $this->db->affected_rows($resql);
		}
		$this->report['orphans_deleted'] = $orphans;

		// Recreate v1 FKs that may have silently failed (lot FK is dropped in
		// step 6, so it is deliberately not recreated here)
		if (!$this->constraintExists('binloc_product_location', 'fk_binloc_prod_loc_product')) {
			if (!$this->db->query("ALTER TABLE ".$pl." ADD CONSTRAINT fk_binloc_prod_loc_product FOREIGN KEY (fk_product) REFERENCES ".MAIN_DB_PREFIX."product (rowid) ON DELETE CASCADE")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if (!$this->constraintExists('binloc_product_location', 'fk_binloc_prod_loc_entrepot')) {
			if (!$this->db->query("ALTER TABLE ".$pl." ADD CONSTRAINT fk_binloc_prod_loc_entrepot FOREIGN KEY (fk_entrepot) REFERENCES ".MAIN_DB_PREFIX."entrepot (rowid) ON DELETE CASCADE")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		// Baseline counts (verification denominator), taken after orphan cleanup
		$headers = $this->fetchScalar("SELECT COUNT(*) FROM ".$pl);
		if ($headers === false) {
			return -1;
		}
		$this->report['baseline_headers'] = (int) $headers;

		$cells = array();
		for ($n = 1; $n <= 6; $n++) {
			$c = $this->fetchScalar("SELECT COUNT(*) FROM ".$pl." WHERE level".$n."_value IS NOT NULL AND level".$n."_value != ''");
			if ($c === false) {
				return -1;
			}
			$cells[$n] = (int) $c;
		}
		$this->report['baseline_cells'] = $cells;
		$this->report['baseline_cells_total'] = array_sum($cells);

		return 1;
	}

	/**
	 * 2.0.0-2: Create v2 structures.
	 * location_value table (created by _load_tables on enable, but ensured here
	 * for direct runs), position column, index changes, level_options rebuild.
	 *
	 * @return int
	 */
	protected function stepCreateStructures()
	{
		$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';
		$lo = MAIN_DB_PREFIX.'binloc_level_options';
		$lv = MAIN_DB_PREFIX.'binloc_location_value';

		if (!$this->tableExists('binloc_location_value')) {
			$sql = "CREATE TABLE ".$lv." (
				rowid INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
				fk_location INTEGER NOT NULL,
				fk_level INTEGER NOT NULL,
				fk_option INTEGER DEFAULT NULL,
				value VARCHAR(64) DEFAULT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) ENGINE=InnoDB";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if (!$this->indexExists('binloc_location_value', 'uk_binloc_loc_val')) {
			if (!$this->db->query("ALTER TABLE ".$lv." ADD UNIQUE INDEX uk_binloc_loc_val (fk_location, fk_level)")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if (!$this->indexExists('binloc_location_value', 'idx_binloc_loc_val_level')) {
			$this->db->query("ALTER TABLE ".$lv." ADD INDEX idx_binloc_loc_val_level (fk_level)");
		}
		if (!$this->indexExists('binloc_location_value', 'idx_binloc_loc_val_option')) {
			$this->db->query("ALTER TABLE ".$lv." ADD INDEX idx_binloc_loc_val_option (fk_option)");
		}
		if (!$this->constraintExists('binloc_location_value', 'fk_binloc_loc_val_location')) {
			if (!$this->db->query("ALTER TABLE ".$lv." ADD CONSTRAINT fk_binloc_loc_val_location FOREIGN KEY (fk_location) REFERENCES ".MAIN_DB_PREFIX."binloc_product_location (rowid) ON DELETE CASCADE")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if (!$this->constraintExists('binloc_location_value', 'fk_binloc_loc_val_level')) {
			if (!$this->db->query("ALTER TABLE ".$lv." ADD CONSTRAINT fk_binloc_loc_val_level FOREIGN KEY (fk_level) REFERENCES ".$wl." (rowid) ON DELETE CASCADE")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		// warehouse_levels: position column, drop v1 unique on level_num
		if (!$this->addColumnIfMissing('binloc_warehouse_levels', 'position', 'INTEGER NOT NULL DEFAULT 0 AFTER datatype')) {
			return -1;
		}
		if ($this->indexExists('binloc_warehouse_levels', 'uk_binloc_wh_level')) {
			if (!$this->db->query("ALTER TABLE ".$wl." DROP INDEX uk_binloc_wh_level")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if (!$this->indexExists('binloc_warehouse_levels', 'idx_binloc_wh_level_pos')) {
			$this->db->query("ALTER TABLE ".$wl." ADD INDEX idx_binloc_wh_level_pos (fk_entrepot, position)");
		}

		// level_options rebuild (v1 shape had fk_entrepot/level_num/option_value).
		// Drop the FK from location_value first — MySQL refuses to drop/rename a
		// parent table while a FK points at it; re-added after the rebuild.
		if ($this->columnExists('binloc_level_options', 'option_value')) {
			if ($this->constraintExists('binloc_location_value', 'fk_binloc_loc_val_option')) {
				if (!$this->db->query("ALTER TABLE ".$lv." DROP FOREIGN KEY fk_binloc_loc_val_option")) {
					$this->error = $this->db->lasterror();
					return -1;
				}
			}

			$count = $this->fetchScalar("SELECT COUNT(*) FROM ".$lo);
			if ($count === false) {
				return -1;
			}

			if ((int) $count > 0 && !$this->tableExists('binloc_level_options_v1')) {
				if (!$this->db->query("RENAME TABLE ".$lo." TO ".$lo."_v1")) {
					$this->error = $this->db->lasterror();
					return -1;
				}
			} elseif ((int) $count === 0) {
				if (!$this->db->query("DROP TABLE ".$lo)) {
					$this->error = $this->db->lasterror();
					return -1;
				}
			}
			$this->report['level_options_v1_rows'] = (int) $count;
		}

		if (!$this->tableExists('binloc_level_options')) {
			$sql = "CREATE TABLE ".$lo." (
				rowid INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER NOT NULL DEFAULT 1,
				fk_level INTEGER NOT NULL,
				value VARCHAR(64) NOT NULL,
				position INTEGER NOT NULL DEFAULT 0,
				active TINYINT NOT NULL DEFAULT 1,
				date_creation DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user_creat INTEGER NOT NULL,
				fk_user_modif INTEGER DEFAULT NULL
			) ENGINE=InnoDB";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			if (!$this->db->query("ALTER TABLE ".$lo." ADD UNIQUE INDEX uk_binloc_lvl_opt (fk_level, value)")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$this->db->query("ALTER TABLE ".$lo." ADD INDEX idx_binloc_lvl_opt_level (fk_level)");
			if (!$this->db->query("ALTER TABLE ".$lo." ADD CONSTRAINT fk_binloc_lvl_opt_level FOREIGN KEY (fk_level) REFERENCES ".$wl." (rowid) ON DELETE CASCADE")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		// Migrate any v1 option rows (rare — the table was dead code in v1)
		if ($this->tableExists('binloc_level_options_v1')) {
			$sql = "INSERT IGNORE INTO ".$lo." (entity, fk_level, value, position, active, date_creation, fk_user_creat)";
			$sql .= " SELECT o.entity, w.rowid, o.option_value, o.position, o.active, o.date_creation, o.fk_user_creat";
			$sql .= " FROM ".$lo."_v1 o";
			$sql .= " INNER JOIN ".$wl." w ON w.entity = o.entity AND w.fk_entrepot = o.fk_entrepot AND w.level_num = o.level_num";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		if (!$this->constraintExists('binloc_location_value', 'fk_binloc_loc_val_option')) {
			if (!$this->db->query("ALTER TABLE ".$lv." ADD CONSTRAINT fk_binloc_loc_val_option FOREIGN KEY (fk_option) REFERENCES ".$lo." (rowid)")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		return 1;
	}

	/**
	 * 2.0.0-3: Seed display position from the v1 level number
	 *
	 * @return int
	 */
	protected function stepPopulatePosition()
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."binloc_warehouse_levels SET position = level_num WHERE position = 0";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * 2.0.0-4: Explode CSV list_values into level_options rows
	 *
	 * @return int
	 */
	protected function stepExplodeCsvOptions()
	{
		$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';
		$lo = MAIN_DB_PREFIX.'binloc_level_options';

		if (!$this->columnExists('binloc_warehouse_levels', 'list_values')) {
			return 1; // already dropped — step previously completed
		}

		$sql = "SELECT rowid, entity, list_values FROM ".$wl." WHERE datatype = 'list' AND list_values IS NOT NULL AND list_values != ''";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$now = $this->db->idate(dol_now());
		$created = 0;

		$this->db->begin();
		foreach ($rows as $obj) {
			$pos = 0;
			$seen = array();
			foreach (explode(',', $obj->list_values) as $part) {
				$part = trim($part);
				if ($part === '' || isset($seen[strtolower($part)])) {
					continue;
				}
				$seen[strtolower($part)] = true;
				$pos++;
				$ins = "INSERT IGNORE INTO ".$lo." (entity, fk_level, value, position, active, date_creation, fk_user_creat)";
				$ins .= " VALUES (".(int) $obj->entity.", ".(int) $obj->rowid.", '".$this->db->escape($part)."', ".$pos.", 1, '".$now."', 0)";
				$res = $this->db->query($ins);
				if (!$res) {
					$this->error = $this->db->lasterror();
					$this->db->rollback();
					return -1;
				}
				$created += $this->db->affected_rows($res);
			}
		}
		$this->db->commit();

		$this->report['csv_options_created'] = $created;
		return 1;
	}

	/**
	 * 2.0.0-5: Dedupe location headers on (entity, product, warehouse, lot-or-0),
	 * keeping the most recently modified row
	 *
	 * @return int
	 */
	protected function stepDedupeHeaders()
	{
		$deleted = $this->dedupeLocationHeaders();
		if ($deleted < 0) {
			return -1;
		}
		$this->report['duplicates_deleted'] = $deleted;
		return 1;
	}

	/**
	 * 2.0.0-6: Convert fk_product_lot NULL -> 0, add the unique index
	 *
	 * @return int
	 */
	protected function stepLotConversion()
	{
		$pl = MAIN_DB_PREFIX.'binloc_product_location';

		if ($this->constraintExists('binloc_product_location', 'fk_binloc_prod_loc_lot')) {
			if (!$this->db->query("ALTER TABLE ".$pl." DROP FOREIGN KEY fk_binloc_prod_loc_lot")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		if (!$this->db->query("UPDATE ".$pl." SET fk_product_lot = 0 WHERE fk_product_lot IS NULL")) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if (!$this->db->query("ALTER TABLE ".$pl." MODIFY fk_product_lot INTEGER NOT NULL DEFAULT 0")) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if (!$this->indexExists('binloc_product_location', 'uk_binloc_prod_loc')) {
			// Close the race window: dedupe again immediately before creating the index
			if ($this->dedupeLocationHeaders() < 0) {
				return -1;
			}
			if (!$this->db->query("ALTER TABLE ".$pl." ADD UNIQUE INDEX uk_binloc_prod_loc (entity, fk_product, fk_entrepot, fk_product_lot)")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		// The v1 non-unique composite is redundant next to the unique index
		if ($this->indexExists('binloc_product_location', 'idx_binloc_prod_loc')) {
			$this->db->query("ALTER TABLE ".$pl." DROP INDEX idx_binloc_prod_loc");
		}

		return 1;
	}

	/**
	 * 2.0.0-7: Explode level1..6 values into location_value child rows.
	 * Batched with a resume cursor. Zero-loss rules: a populated column with no
	 * level config gets an on-the-fly "(legacy)" text level; a list value not
	 * present in the options gets a legacy option row.
	 *
	 * @return int
	 */
	protected function stepExplodeLevelValues()
	{
		$pl = MAIN_DB_PREFIX.'binloc_product_location';
		$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';
		$lo = MAIN_DB_PREFIX.'binloc_level_options';
		$lv = MAIN_DB_PREFIX.'binloc_location_value';

		if (!$this->columnExists('binloc_product_location', 'level1_value')) {
			return 1; // columns already dropped — nothing to explode
		}

		// Level config cache: "entrepot:num" => {rowid, datatype}
		$levels = array();
		$resql = $this->db->query("SELECT rowid, fk_entrepot, level_num, datatype FROM ".$wl);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$levels[$obj->fk_entrepot.':'.$obj->level_num] = $obj;
		}
		$this->db->free($resql);

		// Option cache: "level:lowercase(value)" => option rowid
		$options = array();
		$resql = $this->db->query("SELECT rowid, fk_level, value FROM ".$lo);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$options[$obj->fk_level.':'.strtolower($obj->value)] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$now = $this->db->idate(dol_now());
		$cursor = (int) dolibarr_get_const($this->db, 'BINLOC_DB_MIGRATION_CURSOR', 0);
		$legacyLevels = 0;
		$legacyOptions = 0;
		$valuesWritten = isset($this->report['values_written']) ? (int) $this->report['values_written'] : 0;

		while (true) {
			$sql = "SELECT rowid, entity, fk_entrepot,";
			$sql .= " level1_value, level2_value, level3_value, level4_value, level5_value, level6_value";
			$sql .= " FROM ".$pl." WHERE rowid > ".$cursor." ORDER BY rowid ASC";
			$sql .= " ".$this->db->plimit(500);

			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$batch = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$batch[] = $obj;
			}
			$this->db->free($resql);

			if (empty($batch)) {
				break;
			}

			$this->db->begin();
			foreach ($batch as $row) {
				for ($n = 1; $n <= 6; $n++) {
					$val = $row->{'level'.$n.'_value'};
					if ($val === null || $val === '') {
						continue;
					}

					$key = $row->fk_entrepot.':'.$n;
					if (!isset($levels[$key])) {
						// Populated column with no config: create a legacy text level
						$ins = "INSERT INTO ".$wl." (entity, fk_entrepot, level_num, label, datatype, position, active, date_creation, fk_user_creat)";
						$ins .= " VALUES (".(int) $row->entity.", ".(int) $row->fk_entrepot.", ".$n.", 'Level ".$n." (legacy)', 'text', ".$n.", 1, '".$now."', 0)";
						if (!$this->db->query($ins)) {
							$this->error = $this->db->lasterror();
							$this->db->rollback();
							return -1;
						}
						$cfg = new stdClass();
						$cfg->rowid = $this->db->last_insert_id($wl);
						$cfg->datatype = 'text';
						$levels[$key] = $cfg;
						$legacyLevels++;
					}
					$cfg = $levels[$key];

					$fk_option = 'NULL';
					$value_sql = 'NULL';
					if ($cfg->datatype === 'list') {
						$okey = $cfg->rowid.':'.strtolower($val);
						if (!isset($options[$okey])) {
							// Orphaned list value: keep it as a legacy option, sorted last
							$legacyOptions++;
							$ins = "INSERT INTO ".$lo." (entity, fk_level, value, position, active, date_creation, fk_user_creat)";
							$ins .= " VALUES (".(int) $row->entity.", ".(int) $cfg->rowid.", '".$this->db->escape($val)."', ".(1000 + $legacyOptions).", 1, '".$now."', 0)";
							if (!$this->db->query($ins)) {
								$this->error = $this->db->lasterror();
								$this->db->rollback();
								return -1;
							}
							$options[$okey] = (int) $this->db->last_insert_id($lo);
						}
						$fk_option = (int) $options[$okey];
					} else {
						$value_sql = "'".$this->db->escape($val)."'";
					}

					$ins = "INSERT INTO ".$lv." (fk_location, fk_level, fk_option, value)";
					$ins .= " VALUES (".(int) $row->rowid.", ".(int) $cfg->rowid.", ".$fk_option.", ".$value_sql.")";
					$ins .= " ON DUPLICATE KEY UPDATE fk_option = VALUES(fk_option), value = VALUES(value)";
					if (!$this->db->query($ins)) {
						$this->error = $this->db->lasterror();
						$this->db->rollback();
						return -1;
					}
					$valuesWritten++;
				}
				$cursor = (int) $row->rowid;
			}
			$this->db->commit();
			dolibarr_set_const($this->db, 'BINLOC_DB_MIGRATION_CURSOR', $cursor, 'chaine', 0, '', 0);
			$this->report['values_written'] = $valuesWritten;
			$this->saveReport();
		}

		$this->report['legacy_levels_created'] = $legacyLevels + (isset($this->report['legacy_levels_created']) ? (int) $this->report['legacy_levels_created'] : 0);
		$this->report['legacy_options_created'] = $legacyOptions + (isset($this->report['legacy_options_created']) ? (int) $this->report['legacy_options_created'] : 0);
		dolibarr_del_const($this->db, 'BINLOC_DB_MIGRATION_CURSOR', 0);

		return 1;
	}

	/**
	 * 2.0.0-8: Verification. Blocks the destructive step on any mismatch.
	 *
	 * @return int
	 */
	protected function stepVerify()
	{
		$failures = $this->runVerification();
		if ($failures === false) {
			return -1;
		}
		if (!empty($failures)) {
			$this->error = 'verification failed: '.implode('; ', $failures);
			$this->report['verification_failures'] = $failures;
			return -1;
		}
		$this->report['verification'] = 'passed';
		return 1;
	}

	/**
	 * Data-integrity checks between the legacy columns and the v2 tables.
	 * Also callable standalone from the admin/debug pages.
	 *
	 * @return array|false List of human-readable failure strings (empty = all good), false on query error
	 */
	public function runVerification()
	{
		$pl = MAIN_DB_PREFIX.'binloc_product_location';
		$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';
		$lo = MAIN_DB_PREFIX.'binloc_level_options';
		$lv = MAIN_DB_PREFIX.'binloc_location_value';

		$failures = array();

		// Every legacy cell must have a migrated counterpart (only while legacy
		// columns exist). The check is one-sided on purpose: rows created via
		// the v2 UI between a failed run and a retry have no legacy cells, and
		// must not fail verification.
		if ($this->columnExists('binloc_product_location', 'level1_value')) {
			for ($n = 1; $n <= 6; $n++) {
				$sql = "SELECT COUNT(*) FROM ".$pl." p";
				$sql .= " WHERE p.level".$n."_value IS NOT NULL AND p.level".$n."_value != ''";
				$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$lv." v";
				$sql .= " INNER JOIN ".$wl." w ON w.rowid = v.fk_level";
				$sql .= " WHERE v.fk_location = p.rowid AND w.level_num = ".$n." AND w.fk_entrepot = p.fk_entrepot)";
				$missing = $this->fetchScalar($sql);
				if ($missing === false) {
					return false;
				}
				if ((int) $missing > 0) {
					$failures[] = $missing." level ".$n." cell(s) without a migrated value row";
				}
			}
		}

		// Exactly one of fk_option / value per row
		$sql = "SELECT COUNT(*) FROM ".$lv;
		$sql .= " WHERE (fk_option IS NULL AND (value IS NULL OR value = ''))";
		$sql .= " OR (fk_option IS NOT NULL AND value IS NOT NULL AND value != '')";
		$bad = $this->fetchScalar($sql);
		if ($bad === false) {
			return false;
		}
		if ((int) $bad > 0) {
			$failures[] = $bad." value rows with both or neither of fk_option/value";
		}

		// Referential orphans (explicit checks — FK creation may have failed on some installs)
		$checks = array(
			'value rows without location' => "SELECT COUNT(*) FROM ".$lv." v LEFT JOIN ".$pl." p ON p.rowid = v.fk_location WHERE p.rowid IS NULL",
			'value rows without level'    => "SELECT COUNT(*) FROM ".$lv." v LEFT JOIN ".$wl." w ON w.rowid = v.fk_level WHERE w.rowid IS NULL",
			'value rows without option'   => "SELECT COUNT(*) FROM ".$lv." v LEFT JOIN ".$lo." o ON o.rowid = v.fk_option WHERE v.fk_option IS NOT NULL AND o.rowid IS NULL",
			'options without level'       => "SELECT COUNT(*) FROM ".$lo." o LEFT JOIN ".$wl." w ON w.rowid = o.fk_level WHERE w.rowid IS NULL",
		);
		foreach ($checks as $label => $sql) {
			$c = $this->fetchScalar($sql);
			if ($c === false) {
				return false;
			}
			if ((int) $c > 0) {
				$failures[] = $c." ".$label;
			}
		}

		// Header count is recorded for the report only — user activity between a
		// failed run and a retry legitimately changes it, so it never blocks.
		$current = $this->fetchScalar("SELECT COUNT(*) FROM ".$pl);
		if ($current !== false) {
			$this->report['headers_at_verification'] = (int) $current;
		}

		return $failures;
	}

	/**
	 * 2.0.0-9: Drop the legacy columns. Destructive; only reachable after
	 * verification passed.
	 *
	 * @return int
	 */
	protected function stepDropLegacyColumns()
	{
		$pl = MAIN_DB_PREFIX.'binloc_product_location';
		$wl = MAIN_DB_PREFIX.'binloc_warehouse_levels';

		for ($n = 1; $n <= 6; $n++) {
			if ($this->columnExists('binloc_product_location', 'level'.$n.'_value')) {
				if (!$this->db->query("ALTER TABLE ".$pl." DROP COLUMN level".$n."_value")) {
					$this->error = $this->db->lasterror();
					return -1;
				}
			}
		}
		if ($this->columnExists('binloc_warehouse_levels', 'list_values')) {
			if (!$this->db->query("ALTER TABLE ".$wl." DROP COLUMN list_values")) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		if ($this->tableExists('binloc_level_options_v1')) {
			$this->db->query("DROP TABLE ".MAIN_DB_PREFIX."binloc_level_options_v1");
		}

		return 1;
	}

	// ------------------------------------------------------------------
	// Shared helpers
	// ------------------------------------------------------------------

	/**
	 * Delete duplicate headers on (entity, product, warehouse, lot-or-0),
	 * keeping the row with the newest tms (greatest rowid as tie-break)
	 *
	 * @return int Number of rows deleted, or -1 on error
	 */
	protected function dedupeLocationHeaders()
	{
		$pl = MAIN_DB_PREFIX.'binloc_product_location';

		$sql = "SELECT a.rowid FROM ".$pl." a";
		$sql .= " INNER JOIN ".$pl." b ON a.entity = b.entity";
		$sql .= " AND a.fk_product = b.fk_product AND a.fk_entrepot = b.fk_entrepot";
		$sql .= " AND IFNULL(a.fk_product_lot, 0) = IFNULL(b.fk_product_lot, 0)";
		$sql .= " AND (a.tms < b.tms OR (a.tms = b.tms AND a.rowid < b.rowid))";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		if (empty($ids)) {
			return 0;
		}

		dol_syslog('BinlocMigration: deleting duplicate location rows '.implode(',', $ids), LOG_WARNING);

		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".$pl." WHERE rowid IN (".implode(',', $ids).")")) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return count($ids);
	}

	/**
	 * @param string $table Table name without prefix
	 * @return bool
	 */
	protected function tableExists($table)
	{
		$sql = "SELECT COUNT(*) FROM information_schema.TABLES";
		$sql .= " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->escape(MAIN_DB_PREFIX.$table)."'";
		return ((int) $this->fetchScalar($sql)) > 0;
	}

	/**
	 * @param string $table  Table name without prefix
	 * @param string $column Column name
	 * @return bool
	 */
	protected function columnExists($table, $column)
	{
		$sql = "SELECT COUNT(*) FROM information_schema.COLUMNS";
		$sql .= " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->escape(MAIN_DB_PREFIX.$table)."'";
		$sql .= " AND COLUMN_NAME = '".$this->db->escape($column)."'";
		return ((int) $this->fetchScalar($sql)) > 0;
	}

	/**
	 * @param string $table Table name without prefix
	 * @param string $index Index name
	 * @return bool
	 */
	protected function indexExists($table, $index)
	{
		$sql = "SELECT COUNT(*) FROM information_schema.STATISTICS";
		$sql .= " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->escape(MAIN_DB_PREFIX.$table)."'";
		$sql .= " AND INDEX_NAME = '".$this->db->escape($index)."'";
		return ((int) $this->fetchScalar($sql)) > 0;
	}

	/**
	 * @param string $table      Table name without prefix
	 * @param string $constraint Constraint name
	 * @return bool
	 */
	protected function constraintExists($table, $constraint)
	{
		$sql = "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS";
		$sql .= " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->escape(MAIN_DB_PREFIX.$table)."'";
		$sql .= " AND CONSTRAINT_NAME = '".$this->db->escape($constraint)."'";
		return ((int) $this->fetchScalar($sql)) > 0;
	}

	/**
	 * Add a column when absent. Unlike the v1 helper this surfaces failures.
	 *
	 * @param string $table      Table name without prefix
	 * @param string $column     Column name
	 * @param string $definition Column definition
	 * @return bool
	 */
	protected function addColumnIfMissing($table, $column, $definition)
	{
		if ($this->columnExists($table, $column)) {
			return true;
		}
		if (!$this->db->query("ALTER TABLE ".MAIN_DB_PREFIX.$table." ADD COLUMN ".$column." ".$definition)) {
			$this->error = $this->db->lasterror();
			return false;
		}
		return true;
	}

	/**
	 * Run a single-value SELECT
	 *
	 * @param string $sql Query returning one row, one column
	 * @return string|false
	 */
	protected function fetchScalar($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$row = $this->db->fetch_row($resql);
		$this->db->free($resql);
		return $row ? $row[0] : '0';
	}

	/**
	 * @return string Stored schema version ('' if none)
	 */
	protected function getDbVersion()
	{
		return (string) dolibarr_get_const($this->db, 'BINLOC_DB_VERSION', 0);
	}

	/**
	 * @param string $version Version to stamp
	 * @return void
	 */
	protected function setDbVersion($version)
	{
		dolibarr_set_const($this->db, 'BINLOC_DB_VERSION', $version, 'chaine', 0, '', 0);
	}

	/**
	 * @return void
	 */
	protected function clearError()
	{
		dolibarr_del_const($this->db, 'BINLOC_DB_MIGRATION_ERROR', 0);
	}

	/**
	 * @return void
	 */
	protected function loadReport()
	{
		$raw = dolibarr_get_const($this->db, 'BINLOC_DB_MIGRATION_REPORT', 0);
		$decoded = $raw ? json_decode($raw, true) : null;
		$this->report = is_array($decoded) ? $decoded : array();
	}

	/**
	 * @return void
	 */
	protected function saveReport()
	{
		dolibarr_set_const($this->db, 'BINLOC_DB_MIGRATION_REPORT', json_encode($this->report), 'chaine', 0, '', 0);
	}
}
