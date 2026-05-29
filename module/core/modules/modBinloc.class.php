<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/modBinloc.class.php
 * \ingroup binloc
 * \brief   Module descriptor for Binloc — warehouse bin location tracker
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Class modBinloc
 *
 * Module descriptor for Binloc — track product locations within warehouses
 * using configurable bin/shelf/row levels.
 */
class modBinloc extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);

		$this->numero        = 530100;
		$this->rights_class  = 'binloc';
		$this->family        = 'products';
		$this->module_position = 501;
		$this->name          = preg_replace('/^mod/i', '', get_class($this));
		$this->description   = 'Track product locations within warehouses using configurable bin/shelf/row levels';
		$this->descriptionlong = 'Each warehouse defines its own location hierarchy (e.g. Row/Bay/Shelf/Bin or Case/Drawer/Bin). Products can have different location coordinates in each warehouse they occupy. Includes bulk assignment, per-warehouse and per-product views.';
		$this->editor_name   = 'Zachary Melo';
		$this->version       = '1.6.2';
		$this->const_name    = 'MAIN_MODULE_BINLOC';
		$this->picto         = 'stock';

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array(
				'data' => array(
					'warehousecard',
					'productcard',
					'productlotcard',
					'ordersupplierdispatch',
				),
			),
		);

		$this->dirs = array();

		$this->config_page_url = array('setup.php@binloc');

		$this->depends      = array('modStock');
		$this->requiredby   = array();
		$this->conflictwith = array();

		$this->langfiles = array('binloc@binloc');

		$this->phpmin                = array(7, 0);
		$this->need_dolibarr_version = array(22, 0);

		// Constants
		$this->const = array(
			array('BINLOC_CLEAR_ON_ZERO_STOCK', 'chaine', '0', 'Auto-clear location when product stock in warehouse drops to zero', 0, 'current', 1),
		);

		// Tabs on other object cards
		$this->tabs = array();
		$this->tabs[] = array('data' => 'product:+binloc:BinLocations:binloc@binloc:isModEnabled(\'binloc\'):/binloc/tab_product_locations.php?id=__ID__');
		$this->tabs[] = array('data' => 'stock:+binloc:BinLocations:binloc@binloc:isModEnabled(\'binloc\'):/binloc/tab_warehouse_locations.php?id=__ID__');
		$this->tabs[] = array('data' => 'mo@mrp:+binloc:BinLocations:binloc@binloc:isModEnabled(\'binloc\'):/binloc/tab_mo_locations.php?id=__ID__');
		$this->tabs[] = array('data' => 'reception:+binloc:BinPlacement:binloc@binloc:isModEnabled(\'binloc\'):/binloc/tab_reception_locations.php?id=__ID__');
		// Lot/serial: location is shown inline on the card via addMoreActionsButtons hook

		// Permissions
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = 530101;
		$this->rights[$r][1] = 'Read bin locations';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = 530102;
		$this->rights[$r][1] = 'Create/modify bin locations';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		$r++;
		$this->rights[$r][0] = 530103;
		$this->rights[$r][1] = 'Configure warehouse levels';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'admin';

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type'     => 'left',
			'titre'    => 'BulkBinAssign',
			'mainmenu' => 'products',
			'leftmenu' => 'binloc_bulk',
			'url'      => '/binloc/bulk_assign.php',
			'langs'    => 'binloc@binloc',
			'position' => 210,
			'enabled'  => 'isModEnabled("binloc")',
			'perms'    => '$user->hasRight("binloc", "write")',
			'target'   => '',
			'user'     => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type'     => 'left',
			'titre'    => 'WarehouseLevels',
			'mainmenu' => 'products',
			'leftmenu' => 'binloc_levels',
			'url'      => '/binloc/admin/warehouse_levels.php',
			'langs'    => 'binloc@binloc',
			'position' => 211,
			'enabled'  => 'isModEnabled("binloc")',
			'perms'    => '$user->hasRight("binloc", "admin")',
			'target'   => '',
			'user'     => 2,
		);
	}

	/**
	 * Function called when module is enabled
	 *
	 * @param  string $options Options when enabling module
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/binloc/sql/');
		if ($result < 0) {
			return -1;
		}

		// Idempotent schema migrations for existing installs
		$this->_migrate_schema();

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled
	 *
	 * @param  string $options Options when disabling module
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}

	/**
	 * Idempotent schema migrations — applied on init() for existing installs
	 *
	 * Each migration checks the current column state before running ALTER.
	 * Safe to run on fresh installs (no-op) and existing ones (adds missing columns).
	 *
	 * @return void
	 */
	private function _migrate_schema()
	{
		// Migration 1.2.0: add fk_product_lot column to binloc_product_location
		$this->_add_column_if_missing(
			'binloc_product_location',
			'fk_product_lot',
			'INTEGER DEFAULT NULL AFTER fk_entrepot'
		);

		// Migration 1.5.0: add datatype and list_values columns to warehouse_levels
		$this->_add_column_if_missing(
			'binloc_warehouse_levels',
			'datatype',
			"VARCHAR(16) NOT NULL DEFAULT 'text' AFTER label"
		);
		$this->_add_column_if_missing(
			'binloc_warehouse_levels',
			'list_values',
			'VARCHAR(1024) DEFAULT NULL AFTER datatype'
		);
	}

	/**
	 * Add a column to a table if it doesn't already exist
	 *
	 * @param  string $table      Table name (without prefix)
	 * @param  string $column     Column name
	 * @param  string $definition Column type/definition
	 * @return void
	 */
	private function _add_column_if_missing($table, $column, $definition)
	{
		$full_table = MAIN_DB_PREFIX.$table;

		$sql = "SHOW COLUMNS FROM ".$full_table." LIKE '".$this->db->escape($column)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}

		$exists = ($this->db->num_rows($resql) > 0);
		$this->db->free($resql);

		if (!$exists) {
			$alter = "ALTER TABLE ".$full_table." ADD COLUMN ".$column." ".$definition;
			$this->db->query($alter);
		}
	}
}
