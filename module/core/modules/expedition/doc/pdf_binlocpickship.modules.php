<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/expedition/doc/pdf_binlocpickship.modules.php
 * \ingroup binloc
 * \brief   Pick sheet for a shipment: bins of the exact warehouses and lots shipped
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
dol_include_once('/binloc/core/modules/binloc/binloc_sheet_pdf.class.php');

/**
 * Shipment pick sheet
 */
class pdf_binlocpickship extends ModelePdfExpedition
{
	use BinlocSheetPdfTrait;

	/**
	 * @var string Dolibarr version of the loaded document
	 */
	public $version = 'dolibarr';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->binlocSheetInit($db, 'binlocpickship', 'BinlocPickSheetShipmentModelDesc');
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Build the PDF onto disk
	 *
	 * @param  Expedition $object          Shipment
	 * @param  Translate  $outputlangs     Output language
	 * @param  string     $srctemplatepath Unused
	 * @param  int        $hidedetails     Unused
	 * @param  int        $hidedesc        Unused
	 * @param  int        $hideref         Unused
	 * @return int                         1 OK, 0 KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf, $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (empty($conf->expedition->dir_output)) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', 'expedition');
			return 0;
		}
		$outputlangs->load('binloc@binloc');

		$moved = empty($object->specimen) && binloc_sheet_shipment_stock_moved($object);

		return $this->binlocSheetGenerate($object, $outputlangs, array(
			'kind'          => 'pick',
			'dir'           => $conf->expedition->dir_output.'/sending',
			'date'          => !empty($object->date_delivery) ? $object->date_delivery : (!empty($object->date_creation) ? $object->date_creation : 0),
			'watermark'     => ((int) $object->status === 0) ? getDolGlobalString('SHIPPING_DRAFT_WATERMARK') : '',
			'banner'        => $moved ? $outputlangs->transnoentities('SheetStockAlreadyMoved') : '',
			'default_const' => 'EXPEDITION_ADDON_PDF',
			'provider'      => 'binloc_sheet_rows_from_shipment',
		));
	}
}
