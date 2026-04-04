<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/modWareloc.class.php
 * \ingroup wareloc
 * \brief   Module descriptor for Wareloc v2 — warehouse hierarchy tree builder
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Class modWareloc
 *
 * Module descriptor for Wareloc v2 — warehouse hierarchy tree builder.
 */
class modWareloc extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);

		$this->numero        = 530000;
		$this->rights_class  = 'wareloc';
		$this->family        = 'products';
		$this->module_position = 500;
		$this->name          = preg_replace('/^mod/i', '', get_class($this));
		$this->description   = 'Warehouse hierarchy tree builder — configure and manage bin-level sub-location trees using native Dolibarr warehouse nesting';
		$this->descriptionlong = 'Wareloc v2 extends native Dolibarr warehouse nesting (fk_parent) with a tree-builder UI, per-warehouse depth labeling, and enhanced reception/stock UX. Each root warehouse defines its own level names independently (e.g. Packout: Toolbox → Drawer → Compartment; Shelving: Row → Shelf → Bin).';
		$this->editor_name   = 'Zachary Melo';
		$this->version       = '2.1.2';
		$this->const_name    = 'MAIN_MODULE_WARELOC';
		$this->picto         = 'stock';

		$this->module_parts = array(
			'hooks' => array(
				'entrepotcard',
				'receptioncard',
			),
		);

		$this->phpmin                = array(7, 0);
		$this->need_dolibarr_version = array(16, 0);

		$this->const = array();
		$this->tabs  = array();

		// Permissions
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = 530001;
		$this->rights[$r][1] = 'Configure warehouse hierarchy settings and level names';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'admin';
		$this->rights[$r][5] = 'write';

		// Menus
		$this->menu = array();
		$r = 0;

		// Products > Warehouses: Hierarchy Tree Builder
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type'     => 'left',
			'titre'    => 'WarelocTreeBuilder',
			'mainmenu' => 'products',
			'leftmenu' => 'wareloc_tree',
			'url'      => '/wareloc/warehouse_tree.php',
			'langs'    => 'wareloc@wareloc',
			'position' => 200,
			'enabled'  => 'isModEnabled("wareloc")',
			'perms'    => '$user->admin',
			'target'   => '',
			'user'     => 2,
		);

		// Products > Warehouses: Level Names (setup)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type'     => 'left',
			'titre'    => 'WarelocLevelNames',
			'mainmenu' => 'products',
			'leftmenu' => 'wareloc_setup',
			'url'      => '/wareloc/admin/setup.php',
			'langs'    => 'wareloc@wareloc',
			'position' => 201,
			'enabled'  => 'isModEnabled("wareloc")',
			'perms'    => '$user->admin',
			'target'   => '',
			'user'     => 2,
		);
	}

	/**
	 * Function called when module is enabled
	 *
	 * @param  string $options  Options when enabling module
	 * @return int              1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/wareloc/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled
	 *
	 * @param  string $options  Options when disabling module
	 * @return int              1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
