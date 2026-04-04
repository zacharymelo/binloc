<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/triggers/interface_99_modWareloc_WarelocTrigger.class.php
 * \ingroup wareloc
 * \brief   Wareloc v2 trigger — event logging skeleton
 *
 * v2 no longer auto-creates placement records on reception.
 * Stock is tracked natively via llx_stock_mouvement when a reception is
 * validated against a leaf warehouse. This trigger retains the skeleton
 * for future event-driven extensions.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceWarelocTrigger
 *
 * Wareloc v2 trigger — event logging skeleton.
 */
class InterfaceWarelocTrigger extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);

		$this->name        = preg_replace('/^Interface/i', '', get_class($this));
		$this->family      = 'wareloc';
		$this->description = 'Wareloc v2 event trigger';
		$this->version     = '2.1.2';
		$this->picto       = 'stock';
	}

	/**
	 * Function called when a Dolibarr business event fires.
	 *
	 * @param  string    $action  Event code
	 * @param  object    $object  Object the event is about
	 * @param  User      $user    User who triggered the event
	 * @param  Translate $langs   Language object
	 * @param  Conf      $conf    Config object
	 * @return int                0 = no action taken, 1 = action taken, -1 = error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('wareloc')) {
			return 0;
		}

		switch ($action) {
			// Reserved for future v2 event hooks
			// e.g. ENTREPOT_CREATE, ENTREPOT_MODIFY, RECEPTION_VALIDATE
			default:
				return 0;
		}
	}
}
