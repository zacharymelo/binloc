<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/mrp/doc/pdf_binlocpickmo.modules.php
 * \ingroup binloc
 * \brief   Pick sheet for a manufacturing order: materials still to consume,
 *          then where the finished goods still to produce are put away
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/mrp/modules_mo.php';
dol_include_once('/binloc/core/modules/binloc/binloc_sheet_pdf.class.php');

/**
 * Manufacturing order pick sheet
 */
class pdf_binlocpickmo extends ModelePDFMo
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
		$this->binlocSheetInit($db, 'binlocpickmo', 'BinlocPickSheetMoModelDesc');
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Build the PDF onto disk
	 *
	 * @param  Mo        $object          Manufacturing order
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
		if (empty($conf->mrp->dir_output)) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', 'mrp');
			return 0;
		}

		return $this->binlocSheetGenerate($object, $outputlangs, array(
			'kind'          => 'pick',
			'dir'           => $conf->mrp->dir_output,
			'date'          => !empty($object->date_start_planned) ? $object->date_start_planned : (!empty($object->date_creation) ? $object->date_creation : 0),
			'watermark'     => '',
			'banner'        => '',
			'default_const' => 'MRP_MO_ADDON_PDF',
			'sections'      => array(
				array('kind' => 'pick', 'title' => 'SheetSectionMaterials', 'provider' => 'binloc_sheet_rows_from_mo_materials'),
				array('kind' => 'place', 'title' => 'SheetSectionOutput', 'provider' => 'binloc_sheet_rows_from_mo_output'),
			),
		));
	}
}
