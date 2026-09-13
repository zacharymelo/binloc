<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/reception/doc/pdf_binlocplace.modules.php
 * \ingroup binloc
 * \brief   Place sheet for a reception: where each received line goes
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/reception/modules_reception.php';
dol_include_once('/binloc/core/modules/binloc/binloc_sheet_pdf.class.php');

/**
 * Reception place sheet
 */
class pdf_binlocplace extends ModelePdfReception
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
		$this->binlocSheetInit($db, 'binlocplace', 'BinlocPlaceSheetModelDesc');
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Build the PDF onto disk
	 *
	 * @param  Reception $object          Reception
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
		global $conf, $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (empty($conf->reception->dir_output)) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', 'reception');
			return 0;
		}

		return $this->binlocSheetGenerate($object, $outputlangs, array(
			'kind'          => 'place',
			'dir'           => $conf->reception->dir_output,
			'date'          => !empty($object->date_reception) ? $object->date_reception : (!empty($object->date_creation) ? $object->date_creation : 0),
			'watermark'     => ((int) $object->status === 0) ? getDolGlobalString('RECEPTION_DRAFT_WATERMARK') : '',
			'banner'        => '',
			'default_const' => 'RECEPTION_ADDON_PDF',
			'provider'      => 'binloc_sheet_rows_from_reception',
		));
	}
}
