<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    core/modules/binloc/binloc_sheet_pdf.class.php
 * \ingroup binloc
 * \brief   Shared renderer for the pick / place sheet document models
 *
 * A trait because the three models must extend three different core bases
 * (ModelePDFCommandes, ModelePdfExpedition, ModelePdfReception). Rows come
 * from lib/binloc_sheets.lib.php; this file only draws.
 *
 * Two rules every model relies on:
 * - the file is <REF>-picksheet.pdf / <REF>-placesheet.pdf and
 *   update_main_doc_field is 0, so the commercial PDF stays the object's
 *   main document (default email attachment);
 * - after writing, the object's model_pdf is put back to the site default
 *   (core saves the model chosen in the Documents block onto the object and
 *   regenerates THAT model on validate / line edits).
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/binloc/lib/binloc_sheets.lib.php');

/**
 * Pick / place sheet rendering
 */
trait BinlocSheetPdfTrait
{
	/**
	 * Common constructor body
	 *
	 * @param  DoliDB $db       Database handler
	 * @param  string $name     Model name (file pdf_<name>.modules.php)
	 * @param  string $desc_key Translation key of the description
	 * @return void
	 */
	protected function binlocSheetInit($db, $name, $desc_key)
	{
		global $langs, $mysoc;

		$langs->load('binloc@binloc');

		$this->db = $db;
		$this->name = $name;
		$this->description = $langs->trans($desc_key);
		$this->update_main_doc_field = 0;

		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
		$this->option_logo = 0;
		$this->option_draft_watermark = 1;
		$this->watermark = '';

		if ($mysoc !== null) {
			$this->emetteur = $mysoc;
		}
	}

	/**
	 * Build rows for an object (or specimen rows) and write the sheet
	 *
	 * @param  CommonObject $object      Order, shipment or reception
	 * @param  Translate    $outputlangs Output language
	 * @param  array        $opts        kind ('pick'|'place'), dir, file_base, date, watermark, banner, default_const, provider (callable returning rows)
	 * @return int                       1 OK, 0 KO
	 */
	protected function binlocSheetGenerate($object, $outputlangs, $opts)
	{
		$outputlangs->loadLangs(array('main', 'dict', 'companies', 'products', 'stocks', 'productbatch', 'orders', 'sendings', 'receptions', 'binloc@binloc'));

		$suffix = ($opts['kind'] === 'place') ? '-placesheet.pdf' : '-picksheet.pdf';

		if (!empty($object->specimen)) {
			$dir    = $opts['dir'];
			$file   = $dir.'/SPECIMEN'.$suffix;
			$groups = $this->binlocSheetSpecimenGroups($opts['kind']);
		} else {
			$ref    = dol_sanitizeFileName($object->ref);
			$dir    = $opts['dir'].'/'.$ref;
			$file   = $dir.'/'.$ref.$suffix;
			$rows   = call_user_func($opts['provider'], $this->db, $object);
			$groups = binloc_sheet_finalize($this->db, $rows, getDolGlobalInt('BINLOC_SHEET_SPLIT_BY_WAREHOUSE') > 0);
			if (method_exists($object, 'fetch_thirdparty')) {
				$object->fetch_thirdparty();
			}
		}

		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$ok = $this->binlocSheetWrite($object, $outputlangs, $opts, $groups, $file);
		if ($ok > 0) {
			$this->result = array('fullpath' => $file);
			$this->binlocSheetResetModel($object, $opts['default_const']);
		}
		return $ok;
	}

	/**
	 * Draw and save the PDF
	 *
	 * @param  CommonObject $object      Source object
	 * @param  Translate    $outputlangs Output language
	 * @param  array        $opts        See binlocSheetGenerate()
	 * @param  stdClass[]   $groups      From binloc_sheet_finalize()
	 * @param  string       $file        Full output path
	 * @return int                       1 OK, 0 KO
	 */
	protected function binlocSheetWrite($object, $outputlangs, $opts, $groups, $file)
	{
		global $user;

		$pdf = pdf_getInstance($this->format);
		$fs = pdf_getPDFFontSize($outputlangs);
		$pdf->setAutoPageBreak(false, 0);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->Open();
		$pdf->SetDrawColor(128, 128, 128);

		$title = $outputlangs->transnoentities($opts['kind'] === 'place' ? 'PlaceSheet' : 'PickSheet');
		$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
		$pdf->SetSubject($outputlangs->convToOutputCharset($title));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
		if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
			$pdf->SetCompression(false);
		}
		// @phan-suppress-next-line PhanPluginSuspiciousParamOrder
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);

		$cols   = $this->binlocSheetColumns($opts['kind']);
		$bottom = $this->page_hauteur - $this->marge_basse - 8;
		$left   = $this->marge_gauche;
		$right  = $this->page_largeur - $this->marge_droite;
		$split  = getDolGlobalInt('BINLOC_SHEET_SPLIT_BY_WAREHOUSE') > 0;

		if (empty($groups) || (count($groups) === 1 && empty($groups[0]->rows))) {
			$pdf->AddPage();
			$y = $this->binlocSheetHeader($pdf, $object, $opts, null, $outputlangs, $fs, $title);
			$pdf->SetFont('', '', $fs);
			$pdf->SetXY($left, $y + 4);
			$pdf->MultiCell($right - $left, 6, $outputlangs->convToOutputCharset($outputlangs->transnoentities('SheetNothingToList')), 0, 'L');
		}

		foreach ($groups as $group) {
			$pdf->AddPage();
			$y = $this->binlocSheetHeader($pdf, $object, $opts, $split ? $group : null, $outputlangs, $fs, $title);
			$y = $this->binlocSheetColumnTitles($pdf, $cols, $y, $outputlangs, $fs);

			foreach ($group->rows as $row) {
				$cells = $this->binlocSheetCells($row, $opts['kind'], !$split, $outputlangs);

				$h = 7;
				foreach ($cols as $key => $col) {
					if (!isset($cells[$key]) || $cells[$key] === '') {
						continue;
					}
					$pdf->SetFont('', empty($col['bold']) ? '' : 'B', $fs + $col['size']);
					$h = max($h, $pdf->getStringHeight($col['w'], $cells[$key]) + 2);
				}

				if ($y + $h > $bottom) {
					$pdf->AddPage();
					$y = $this->binlocSheetHeader($pdf, $object, $opts, $split ? $group : null, $outputlangs, $fs, $title);
					$y = $this->binlocSheetColumnTitles($pdf, $cols, $y, $outputlangs, $fs);
				}

				$x = $left;
				foreach ($cols as $key => $col) {
					if ($key === 'check') {
						$pdf->Rect($x + 2, $y + 1.5, 4, 4);
					} elseif ($key === 'writein') {
						if ($row->status !== 'assigned') {
							$pdf->Rect($x + 0.5, $y + 1, $col['w'] - 2, $h - 2);
						}
					} elseif (isset($cells[$key]) && $cells[$key] !== '') {
						$pdf->SetFont('', empty($col['bold']) ? '' : 'B', $fs + $col['size']);
						$pdf->MultiCell($col['w'], $h, $cells[$key], 0, $col['align'], false, 0, $x, $y + 1);
					}
					$x += $col['w'];
				}
				$pdf->Line($left, $y + $h, $right, $y + $h);
				$y += $h;
			}
		}

		// Footer on every page
		$nb = $pdf->getNumPages();
		$stamp = dol_print_date(dol_now(), 'dayhour', 'tzuserrel', $outputlangs);
		for ($i = 1; $i <= $nb; $i++) {
			$pdf->setPage($i);
			$pdf->SetFont('', '', $fs - 3);
			$pdf->SetTextColor(100, 100, 100);
			$pdf->SetXY($left, $this->page_hauteur - $this->marge_basse - 5);
			$pdf->MultiCell(($right - $left) / 2, 4, $outputlangs->convToOutputCharset(implode(' · ', array($object->ref, $stamp))), 0, 'L');
			$pdf->SetXY($left + ($right - $left) / 2, $this->page_hauteur - $this->marge_basse - 5);
			$pdf->MultiCell(($right - $left) / 2, 4, $outputlangs->convToOutputCharset(implode(' ', array($outputlangs->transnoentities('Page'), $i, '/', $nb))), 0, 'R');
			$pdf->SetTextColor(0, 0, 0);
		}
		$pdf->lastPage();

		$pdf->Close();
		$pdf->Output($file, 'F');
		dolChmod($file);

		return 1;
	}

	/**
	 * Page header; returns the Y where content starts
	 *
	 * @param  TCPDF         $pdf         PDF
	 * @param  CommonObject  $object      Source object
	 * @param  array         $opts        Options
	 * @param  stdClass|null $group       Warehouse group in split mode, null otherwise
	 * @param  Translate     $outputlangs Output language
	 * @param  int           $fs          Base font size
	 * @param  string        $title       Sheet title
	 * @return float
	 */
	protected function binlocSheetHeader($pdf, $object, $opts, $group, $outputlangs, $fs, $title)
	{
		if (!empty($opts['watermark'])) {
			pdf_watermark($pdf, $outputlangs, $this->page_hauteur, $this->page_largeur, 'mm', $opts['watermark']);
		}

		$left = $this->marge_gauche;
		$w    = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$y    = $this->marge_haute;

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', 'B', $fs + 7);
		$pdf->SetXY($left, $y);
		$pdf->MultiCell($w / 2, 9, $outputlangs->convToOutputCharset($title), 0, 'L');
		if (!empty($this->emetteur->name)) {
			$pdf->SetFont('', '', $fs - 2);
			$pdf->SetXY($left, $y + 9);
			$pdf->MultiCell($w / 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
		}

		$pdf->SetFont('', 'B', $fs + 3);
		$pdf->SetXY($left + $w / 2, $y);
		$pdf->MultiCell($w / 2, 6, $outputlangs->convToOutputCharset($object->ref), 0, 'R');

		$info = array();
		if (!empty($opts['date'])) {
			$info[] = dol_print_date($opts['date'], 'day', false, $outputlangs);
		}
		if (!empty($object->thirdparty) && is_object($object->thirdparty)) {
			$info[] = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
		}
		if (!empty($object->ref_client)) {
			$info[] = $object->ref_client;
		} elseif (!empty($object->ref_customer)) {
			$info[] = $object->ref_customer;
		} elseif (!empty($object->ref_supplier)) {
			$info[] = $object->ref_supplier;
		}
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($left + $w / 2, $y + 6);
		$pdf->MultiCell($w / 2, 4, $outputlangs->convToOutputCharset(implode("\n", $info)), 0, 'R');

		$y = max($pdf->GetY(), $y + 15) + 3;

		if ($group !== null) {
			$wh = $group->warehouse_ref !== '' ? $group->warehouse_ref : $outputlangs->transnoentities('SheetNoWarehouse');
			if ($group->warehouse_label !== '') {
				$wh = implode(' - ', array($wh, $group->warehouse_label));
			}
			$pdf->SetFont('', 'B', $fs + 2);
			$pdf->SetFillColor(240, 240, 240);
			$pdf->SetXY($left, $y);
			$pdf->MultiCell($w, 7, $outputlangs->convToOutputCharset(implode(': ', array($outputlangs->transnoentities('Warehouse'), $wh))), 0, 'L', true);
			$y += 9;
		}

		if (!empty($opts['banner'])) {
			$pdf->SetFont('', 'B', $fs - 1);
			$pdf->SetFillColor(255, 236, 179);
			$text = $outputlangs->convToOutputCharset($opts['banner']);
			$h = $pdf->getStringHeight($w, $text) + 2;
			$pdf->SetXY($left, $y);
			$pdf->MultiCell($w, $h, $text, 1, 'L', true);
			$y += $h + 2;
		}

		return $y;
	}

	/**
	 * Column titles row; returns the Y below it
	 *
	 * @param  TCPDF     $pdf         PDF
	 * @param  array     $cols        Columns
	 * @param  float     $y           Top
	 * @param  Translate $outputlangs Output language
	 * @param  int       $fs          Base font size
	 * @return float
	 */
	protected function binlocSheetColumnTitles($pdf, $cols, $y, $outputlangs, $fs)
	{
		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(225, 225, 225);
		$x = $this->marge_gauche;
		foreach ($cols as $col) {
			$text = $col['title'] !== '' ? $outputlangs->convToOutputCharset($outputlangs->transnoentities($col['title'])) : '';
			$pdf->MultiCell($col['w'], 6, $text, 0, $col['align'], true, 0, $x, $y);
			$x += $col['w'];
		}
		return $y + 7;
	}

	/**
	 * Column definitions (widths in mm; product absorbs the remainder)
	 *
	 * @param  string $kind 'pick' or 'place'
	 * @return array<string,array{w:float,title:string,align:string,bold:int,size:int}>
	 */
	protected function binlocSheetColumns($kind)
	{
		$cols = array();
		$cols['check'] = array('w' => 8, 'title' => '', 'align' => 'C', 'bold' => 0, 'size' => 0);
		$cols['code']  = array('w' => 36, 'title' => 'SheetColBin', 'align' => 'L', 'bold' => 1, 'size' => 3);
		if ($kind === 'place') {
			$cols['writein'] = array('w' => 30, 'title' => 'SheetColWriteIn', 'align' => 'L', 'bold' => 0, 'size' => 0);
		}
		$cols['product'] = array('w' => 0, 'title' => 'Product', 'align' => 'L', 'bold' => 0, 'size' => -1);
		$cols['batch']   = array('w' => 30, 'title' => 'Batch', 'align' => 'L', 'bold' => 0, 'size' => -1);
		$cols['qty']     = array('w' => 14, 'title' => 'Qty', 'align' => 'R', 'bold' => 1, 'size' => 0);
		$cols['notes']   = array('w' => 38, 'title' => 'SheetColNotes', 'align' => 'L', 'bold' => 0, 'size' => -2);

		$usable = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$fixed = 0;
		foreach ($cols as $col) {
			$fixed += $col['w'];
		}
		$cols['product']['w'] = max(30, $usable - $fixed);
		return $cols;
	}

	/**
	 * Text per column for one row (already converted to the output charset)
	 *
	 * @param  stdClass  $row          Sheet row
	 * @param  string    $kind         'pick' or 'place'
	 * @param  bool      $show_wh      Mention the warehouse (combined mode)
	 * @param  Translate $outputlangs  Output language
	 * @return array<string,string>
	 */
	protected function binlocSheetCells($row, $kind, $show_wh, $outputlangs)
	{
		$notes = array();
		if ($show_wh && $row->warehouse_ref !== '') {
			$notes[] = $row->warehouse_ref;
		}
		if ($row->status === 'suggested') {
			$notes[] = $outputlangs->transnoentities('SheetSuggestedBin');
		} elseif ($row->status === 'none' && $row->fk_entrepot > 0) {
			$notes[] = $outputlangs->transnoentities('SheetNoBinAssigned');
		}
		if (!empty($row->bins_elsewhere)) {
			$notes[] = $outputlangs->transnoentities('SheetBinElsewhere', implode(', ', $row->bins_elsewhere));
		}
		if ($row->qty_note !== '') {
			$notes[] = $row->qty_note;
		}
		if ($row->note !== '') {
			$notes[] = $row->note;
		}

		$product = $row->product_ref;
		if ($row->product_label !== '') {
			$product = implode("\n", array($row->product_ref, $row->product_label));
		}

		$cells = array(
			'code'    => $row->code,
			'product' => $product,
			'batch'   => $row->batch,
			'qty'     => (string) price2num($row->qty, 'MS'),
			'notes'   => implode("\n", $notes),
		);
		foreach ($cells as $k => $v) {
			$cells[$k] = $outputlangs->convToOutputCharset(dol_string_nohtmltag((string) $v, 0));
		}
		return $cells;
	}

	/**
	 * Put the object's model_pdf back to the site default after a sheet was
	 * generated (see file header)
	 *
	 * @param  CommonObject $object        Source object
	 * @param  string       $default_const Constant holding the default model for this type
	 * @return void
	 */
	protected function binlocSheetResetModel($object, $default_const)
	{
		if (!empty($object->specimen) || empty($object->id) || empty($object->table_element)) {
			return;
		}
		$ours = array();
		foreach (binloc_sheet_models() as $m) {
			$ours[] = $m['name'];
		}
		$current = isset($object->model_pdf) ? (string) $object->model_pdf : '';
		if (!in_array($current, $ours, true)) {
			return;
		}
		$default = getDolGlobalString($default_const);
		if ($default === $current) {
			return; // the site deliberately made the sheet the default model
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX.$object->table_element;
		$sql .= " SET model_pdf = ".($default !== '' ? "'".$this->db->escape($default)."'" : "NULL");
		$sql .= " WHERE rowid = ".((int) $object->id);
		if ($this->db->query($sql)) {
			$object->model_pdf = $default;
		}
	}

	/**
	 * Fake rows for the admin Preview (specimen objects have no lines)
	 *
	 * @param  string $kind 'pick' or 'place'
	 * @return stdClass[]
	 */
	protected function binlocSheetSpecimenGroups($kind)
	{
		$samples = array(
			array('AL21A1', 'PART-100', 'Hex bolt M8', '', 12, 'assigned'),
			array('AL21A4', 'PART-205', 'Hydraulic hose 1m', 'LOT-2291', 2, 'assigned'),
			array('AR03B2', 'PUMP-7', 'Transfer pump', 'SN-000481', 1, 'suggested'),
			array('', 'KIT-9', 'Seal kit', '', 4, 'none'),
		);
		$rows = array();
		foreach ($samples as $s) {
			$row = binloc_sheet_row(0, 1, 0, $s[3], $s[4]);
			$row->code          = $s[0];
			$row->product_ref   = $s[1];
			$row->product_label = $s[2];
			$row->status        = $s[5];
			$row->warehouse_ref = 'WH-A';
			$rows[] = $row;
		}
		$group = new stdClass();
		$group->fk_entrepot     = 1;
		$group->warehouse_ref   = 'WH-A';
		$group->warehouse_label = '';
		$group->rows            = $rows;
		return array($group);
	}
}
