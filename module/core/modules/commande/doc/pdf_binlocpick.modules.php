<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/commande/doc/pdf_binlocpick.modules.php
 * \ingroup binloc
 * \brief   Pick sheet for a sales order: bins to take the unshipped quantity from
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/commande/modules_commande.php';
dol_include_once('/binloc/core/modules/binloc/binloc_sheet_pdf.class.php');

/**
 * Sales order pick sheet
 */
class pdf_binlocpick extends ModelePDFCommandes
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
		$this->binlocSheetInit($db, 'binlocpick', 'BinlocPickSheetOrderModelDesc');
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Build the PDF onto disk
	 *
	 * @param  Commande  $object          Sales order
	 * @param  Translate $outputlangs     Output language
	 * @param  string    $srctemplatepath Unused
	 * @param  int       $hidedetails     Unused
	 * @param  int       $hidedesc        Unused
	 * @param  int       $hideref         Unused
	 * @return int                        1 OK, 0 KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$dir = getMultidirOutput($object);
		if (empty($dir)) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', 'Null dir');
			return 0;
		}

		return $this->binlocSheetGenerate($object, $outputlangs, array(
			'kind'          => 'pick',
			'dir'           => $dir,
			'date'          => !empty($object->date) ? $object->date : 0,
			'watermark'     => ((int) $object->status === 0) ? getDolGlobalString('COMMANDE_DRAFT_WATERMARK') : '',
			'banner'        => '',
			'default_const' => 'COMMANDE_ADDON_PDF',
			'provider'      => 'binloc_sheet_rows_from_order',
		));
	}
}
