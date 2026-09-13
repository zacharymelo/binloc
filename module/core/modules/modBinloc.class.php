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
		$this->version       = '2.15.4';
		$this->const_name    = 'MAIN_MODULE_BINLOC';
		$this->picto         = 'stock';

		$this->module_parts = array(
			'triggers' => 1,
			// Pick / place sheet document models in core/modules/{commande,expedition,reception}/doc/
			'models' => 1,
			'hooks' => array(
				'data' => array(
					'warehousecard',
					'productlotcard',
					'ordersupplierdispatch',
					// Pick / place sheet shortcut buttons
					'ordercard',
					'expeditioncard',
					'receptioncard',
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
			array('BINLOC_DEBUG_MODE', 'chaine', '0', 'Enable the Binloc diagnostics page', 0, 'current', 1),
			array('BINLOC_SHEET_SPLIT_BY_WAREHOUSE', 'chaine', '0', 'Pick/place sheets: one section per warehouse on its own page', 0, 'current', 0),
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
			'titre'    => 'BinLabels',
			'mainmenu' => 'products',
			'leftmenu' => 'binloc_labels',
			'url'      => '/binloc/labels.php',
			'langs'    => 'binloc@binloc',
			'position' => 211,
			'enabled'  => 'isModEnabled("binloc")',
			'perms'    => '$user->hasRight("binloc", "read")',
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
			'position' => 212,
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

		// Versioned schema/data migration. A failure does not block enabling the
		// module: the error is stored in BINLOC_DB_MIGRATION_ERROR and shown as a
		// banner on the setup page, where the migration can be retried.
		dol_include_once('/binloc/class/binlocmigration.class.php');
		$migration = new BinlocMigration($this->db);
		$migration->run();

		// Warehouse "Bin code prefix" extrafield (fresh installs skip the
		// migration steps, so it is ensured here as well)
		dol_include_once('/binloc/lib/binloc.lib.php');
		binloc_ensure_warehouse_code_extrafield($this->db);

		$this->delete_menus();

		$result = $this->_init(array(), $options);

		// Put back the pick / place sheets that were switched on when the
		// module was last disabled (remove() unregisters them)
		if ($result > 0) {
			global $langs;
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dol_include_once('/binloc/lib/binloc_sheets.lib.php');
			$langs->load('binloc@binloc');
			$models = binloc_sheet_models();
			foreach (explode(',', getDolGlobalString('BINLOC_SHEETS_ACTIVE')) as $type) {
				if (isset($models[$type]) && !binloc_sheet_model_enabled($this->db, $type)) {
					addDocumentModel($models[$type]['name'], $type, $langs->trans($models[$type]['label']));
				}
			}
		}

		return $result;
	}

	/**
	 * Function called when module is disabled
	 *
	 * @param  string $options Options when disabling module
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		global $conf;

		// Unregister the sheet models so object cards don't offer a model
		// whose file can no longer be loaded, remembering which were on so
		// init() restores them (BINLOC_SHEETS_ACTIVE is not in $this->const,
		// so disabling keeps it)
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dol_include_once('/binloc/lib/binloc_sheets.lib.php');
		$active = array();
		foreach (binloc_sheet_models() as $type => $model) {
			if (binloc_sheet_model_enabled($this->db, $type)) {
				$active[] = $type;
			}
			delDocumentModel($model['name'], $type);
		}
		dolibarr_set_const($this->db, 'BINLOC_SHEETS_ACTIVE', implode(',', $active), 'chaine', 0, '', $conf->entity);

		$sql = array();
		return $this->_remove($sql, $options);
	}
}
