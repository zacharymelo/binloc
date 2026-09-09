<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    admin/warehouse_levels.php
 * \ingroup binloc
 * \brief   Per-warehouse level configuration — labels, types, order and list options
 *
 * Levels have stable identity (rowid): edits are in-place updates via
 * applyWarehouseLevels(), so reordering or removing a level never re-labels
 * existing location data. List values are managed per level in the options
 * sub-editor — renaming an option propagates to every assignment because
 * values reference options by rowid.
 *
 * The whole page is ONE form with a single universal Save: level rows, every
 * option's value/description, and the per-level "new value" inputs all submit
 * together, so saving never discards edits made elsewhere on the page. The
 * per-option Disable/Enable/Delete buttons are named submits of the same form
 * — they too save all pending edits before applying their own action.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/binloc/lib/binloc.lib.php');
dol_include_once('/binloc/class/binlocwarehouselevel.class.php');
dol_include_once('/binloc/class/binloclevaloption.class.php');

$langs->loadLangs(array('admin', 'stocks', 'binloc@binloc'));

if (!$user->admin && !$user->hasRight('binloc', 'admin')) {
	accessforbidden();
}

$action      = GETPOST('action', 'aZ09');
$fk_entrepot = GETPOSTINT('fk_entrepot');

$levelObj  = new BinlocWarehouseLevel($db);
$optionObj = new BinlocLevelOption($db);

// Placeholder hints per depth
$level_hints = array(
	1 => 'LevelHint1',
	2 => 'LevelHint2',
	3 => 'LevelHint3',
	4 => 'LevelHint4',
	5 => 'LevelHint5',
	6 => 'LevelHint6',
);

// ---- ACTIONS ----

$current_levels = ($fk_entrepot > 0) ? $levelObj->fetchByWarehouse($fk_entrepot, true) : array();

if ($action === 'savelevels' && $fk_entrepot > 0) {
	$errors = 0;

	// 1) Level rows (labels, types, order, additions, removals)
	$level_ids = GETPOST('level_ids', 'array');
	$labels    = GETPOST('labels', 'array');
	$datatypes = GETPOST('datatypes', 'array');

	$rows = array();
	$position = 0;
	if (is_array($labels)) {
		foreach ($labels as $idx => $label) {
			$label = trim($label);
			if ($label === '') {
				continue;
			}
			$position++;
			$rows[] = array(
				'id'       => isset($level_ids[$idx]) ? (int) $level_ids[$idx] : 0,
				'label'    => $label,
				'datatype' => isset($datatypes[$idx]) ? $datatypes[$idx] : 'text',
				'position' => $position,
			);
		}
	}

	if ($levelObj->applyWarehouseLevels($fk_entrepot, $rows, $user) <= 0) {
		setEventMessages($levelObj->error, null, 'errors');
		$errors++;
	}

	$current_levels = $levelObj->fetchByWarehouse($fk_entrepot, true);

	// Ownership map: every option of this warehouse, keyed by rowid
	$owned_options = array();
	foreach ($current_levels as $cfg) {
		foreach ($cfg->options as $opt) {
			$opt->level_label = $cfg->label;
			$owned_options[(int) $opt->id] = $opt;
		}
	}

	// 2) Delete (named submit; processed first so its row skips the rename pass)
	$delete_id = GETPOSTINT('deleteoption');
	if ($delete_id > 0 && isset($owned_options[$delete_id])) {
		$result = $optionObj->deleteIfUnreferenced($delete_id);
		if ($result > 0) {
			setEventMessages($langs->trans('OptionDeleted'), null, 'mesgs');
		} elseif ($result == -2) {
			setEventMessages($langs->trans('OptionInUseDeactivateInstead', $optionObj->countReferences($delete_id)), null, 'warnings');
			$errors++;
		} else {
			setEventMessages($langs->trans($optionObj->error), null, 'errors');
			$errors++;
		}
	}

	// 3) Option value/description edits (only rows that actually changed)
	$opt_values = GETPOST('opt_value', 'array');
	$opt_descs  = GETPOST('opt_desc', 'array');
	foreach ($owned_options as $opt_id => $opt) {
		if ($opt_id === $delete_id) {
			continue;
		}
		if (!isset($opt_values[$opt_id]) && !isset($opt_descs[$opt_id])) {
			continue; // not on the submitted page
		}
		$new_value = isset($opt_values[$opt_id]) ? trim($opt_values[$opt_id]) : $opt->value;
		$new_desc  = isset($opt_descs[$opt_id]) ? trim($opt_descs[$opt_id]) : $opt->description;
		if ($new_value === $opt->value && $new_desc === $opt->description) {
			continue;
		}
		if ($optionObj->rename($opt_id, $new_value, $user, $new_desc) <= 0) {
			setEventMessages($opt->level_label.' &mdash; '.dol_escape_htmltag($opt->value).': '.$langs->trans($optionObj->error), null, 'errors');
			$errors++;
		}
	}

	// 4) New values (one optional "new value" row per list level)
	$new_values = GETPOST('new_value', 'array');
	$new_descs  = GETPOST('new_desc', 'array');
	foreach ($current_levels as $level_id => $cfg) {
		if ($cfg->datatype !== 'list') {
			continue;
		}
		$value = isset($new_values[$level_id]) ? trim($new_values[$level_id]) : '';
		if ($value === '') {
			continue;
		}
		$max_pos = 0;
		foreach ($cfg->options as $opt) {
			$max_pos = max($max_pos, $opt->position);
		}
		$desc = isset($new_descs[$level_id]) ? trim($new_descs[$level_id]) : '';
		if ($optionObj->create($level_id, $value, $max_pos + 1, $user, $desc) <= 0) {
			setEventMessages($cfg->label.' &mdash; '.dol_escape_htmltag($value).': '.$langs->trans($optionObj->error), null, 'errors');
			$errors++;
		}
	}

	// 5) Toggle active (named submit) — flips the state stored server-side
	$toggle_id = GETPOSTINT('toggleoption');
	if ($toggle_id > 0 && isset($owned_options[$toggle_id]) && $toggle_id !== $delete_id) {
		if ($optionObj->setActive($toggle_id, $owned_options[$toggle_id]->active ? 0 : 1, $user) <= 0) {
			setEventMessages($langs->trans($optionObj->error), null, 'errors');
			$errors++;
		}
	}

	if (!$errors) {
		setEventMessages($langs->trans('LevelsSaved'), null, 'mesgs');
	}
	$action = '';
	$current_levels = $levelObj->fetchByWarehouse($fk_entrepot, true);
}

if ($action === 'copylevels' && $fk_entrepot > 0) {
	$source_wh = GETPOSTINT('source_wh');
	if ($source_wh > 0 && $source_wh != $fk_entrepot) {
		$result = $levelObj->copyFromWarehouse($source_wh, $fk_entrepot, $user);
		if ($result > 0) {
			setEventMessages($langs->trans('CopyLevelsDone'), null, 'mesgs');
		} elseif ($result == -2) {
			setEventMessages($langs->trans('TargetWarehouseHasLevels'), null, 'errors');
		} else {
			setEventMessages($levelObj->error, null, 'errors');
		}
	}
	$action = '';
	$current_levels = $levelObj->fetchByWarehouse($fk_entrepot, true);
}

// ---- VIEW ----

$page_name = 'BinlocSetup';
llxHeader('', $langs->trans($page_name), binloc_help_url());

binloc_print_assets();

$head = binloc_admin_prepare_head();
print dol_get_fiche_head($head, 'warehouselevels', $langs->trans($page_name), -1, 'stock');

// Warehouse selector
print '<div class="marginbottomonly">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
print '<strong>'.$langs->trans('Warehouse').'</strong>: ';
print binloc_render_warehouse_select($db, 'fk_entrepot', $fk_entrepot, 'flat minwidth250', 'onchange="this.form.submit()"');
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Select').'">';
print '</form>';
print '</div>';

if ($fk_entrepot > 0) {
	$warehouses = binloc_get_warehouses($db);

	// ---- Copy from another warehouse (only offered while target is empty) ----
	if (empty($current_levels)) {
		$other_wh_with_levels = array();
		foreach ($warehouses as $wh) {
			if ($wh->rowid == $fk_entrepot) {
				continue;
			}
			$wh_levels = $levelObj->fetchByWarehouse($wh->rowid);
			if (!empty($wh_levels)) {
				$wh->levels = $wh_levels;
				$other_wh_with_levels[] = $wh;
			}
		}

		if (!empty($other_wh_with_levels)) {
			print '<div class="marginbottomonly">';
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="copylevels">';
			print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
			print $langs->trans('CopyFromWarehouse').': ';
			print '<select name="source_wh" class="flat minwidth200">';
			print '<option value="0">---</option>';
			foreach ($other_wh_with_levels as $wh) {
				$label_parts = array();
				foreach ($wh->levels as $lcfg) {
					$label_parts[] = $lcfg->label;
				}
				print '<option value="'.$wh->rowid.'">'.dol_escape_htmltag($wh->ref);
				print ' ('.implode(' &rarr; ', array_map('dol_escape_htmltag', $label_parts)).')';
				print '</option>';
			}
			print '</select>';
			print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('CopyLevels')).'">';
			print '</form>';
			print '</div>';
		}
	}

	// ---- ONE form: level editor + option editors + universal Save ----
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="binloc-level-form">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savelevels">';
	print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';

	print '<table class="noborder centpercent" id="binloc-level-table">';
	print '<tbody>';
	print '<tr class="liste_titre">';
	print '<td class="center" width="60">'.$langs->trans('Level').'</td>';
	print '<td width="220">'.$langs->trans('LevelLabel').'</td>';
	print '<td width="140">'.$langs->trans('Type').'</td>';
	print '<td>'.$langs->trans('Status').'</td>';
	print '<td class="center" width="100"></td>';
	print '</tr>';

	$row_num = 0;
	foreach ($current_levels as $level_id => $cfg) {
		$row_num++;
		$hint = isset($level_hints[$row_num]) ? $langs->trans($level_hints[$row_num]) : '';

		print '<tr class="oddeven binloc-level-row">';
		print '<td class="center opacitymedium"><span class="binloc-level-num">'.$row_num.'</span>';
		print '<input type="hidden" name="level_ids[]" value="'.$level_id.'"></td>';
		print '<td><input type="text" name="labels[]" class="flat minwidth150" value="'.dol_escape_htmltag($cfg->label).'" placeholder="'.dol_escape_htmltag($hint).'"></td>';
		print '<td>';
		print '<select name="datatypes[]" class="flat binloc-type-select">';
		print '<option value="text"'.($cfg->datatype === 'text' ? ' selected' : '').'>'.$langs->trans('TypeText').'</option>';
		print '<option value="number"'.($cfg->datatype === 'number' ? ' selected' : '').'>'.$langs->trans('TypeNumber').'</option>';
		print '<option value="list"'.($cfg->datatype === 'list' ? ' selected' : '').'>'.$langs->trans('TypeList').'</option>';
		print '</select>';
		print '</td>';
		print '<td>'.($cfg->active ? '' : '<span class="opacitymedium binloc-legacy">'.$langs->trans('Disabled').'</span>').'</td>';
		print '<td class="center nowraponall">';
		print '<a href="#" class="binloc-move-up" title="'.$langs->trans('Up').'">&uarr;</a> ';
		print '<a href="#" class="binloc-move-down" title="'.$langs->trans('Down').'">&darr;</a> ';
		print '<a href="#" class="binloc-remove-level" title="'.$langs->trans('RemoveLevel').'">'.img_picto($langs->trans('RemoveLevel'), 'delete').'</a>';
		print '</td>';
		print '</tr>';
	}
	print '</tbody>';
	print '</table>';

	// New-row template — server-rendered once, cloned by JS (single source of markup)
	print '<template id="binloc-level-row-template">';
	print '<tr class="oddeven binloc-level-row">';
	print '<td class="center opacitymedium"><span class="binloc-level-num"></span>';
	print '<input type="hidden" name="level_ids[]" value="0"></td>';
	print '<td><input type="text" name="labels[]" class="flat minwidth150" value=""></td>';
	print '<td>';
	print '<select name="datatypes[]" class="flat binloc-type-select">';
	print '<option value="text">'.$langs->trans('TypeText').'</option>';
	print '<option value="number">'.$langs->trans('TypeNumber').'</option>';
	print '<option value="list">'.$langs->trans('TypeList').'</option>';
	print '</select>';
	print '</td>';
	print '<td></td>';
	print '<td class="center nowraponall">';
	print '<a href="#" class="binloc-move-up" title="'.$langs->trans('Up').'">&uarr;</a> ';
	print '<a href="#" class="binloc-move-down" title="'.$langs->trans('Down').'">&darr;</a> ';
	print '<a href="#" class="binloc-remove-level" title="'.$langs->trans('RemoveLevel').'">'.img_picto($langs->trans('RemoveLevel'), 'delete').'</a>';
	print '</td>';
	print '</tr>';
	print '</template>';

	print '<div class="margintoponly">';
	print '<a href="#" id="binloc-add-level" class="button smallpaddingimp">';
	print img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('AddLevel');
	print '</a>';
	print '</div>';

	// ---- Options sub-editor for list-type levels (same form) ----
	$list_levels = array();
	foreach ($current_levels as $level_id => $cfg) {
		if ($cfg->datatype === 'list') {
			$list_levels[$level_id] = $cfg;
		}
	}

	if (!empty($list_levels)) {
		print '<br><div class="underbanner marginbottomonly"><strong>'.$langs->trans('ListValues').'</strong></div>';
		print '<div class="opacitymedium marginbottomonly small">'.$langs->trans('ListValuesRenameHint').'</div>';

		foreach ($list_levels as $level_id => $cfg) {
			print '<div class="binloc-card">';
			print '<div class="binloc-card-title">'.dol_escape_htmltag($cfg->label);
			if (!$cfg->active) {
				print ' <span class="opacitymedium binloc-legacy">('.$langs->trans('Disabled').')</span>';
			}
			print '</div>';

			print '<table class="noborder">';
			foreach ($cfg->options as $opt) {
				$refs = $optionObj->countReferences($opt->id);
				print '<tr class="oddeven'.($opt->active ? '' : ' binloc-legacy').'">';
				print '<td>';
				print '<input type="text" name="opt_value['.$opt->id.']" class="flat width100" value="'.dol_escape_htmltag($opt->value).'">';
				print ' <input type="text" name="opt_desc['.$opt->id.']" class="flat minwidth150" value="'.dol_escape_htmltag($opt->description).'" placeholder="'.dol_escape_htmltag($langs->trans('OptionDescription')).'" title="'.dol_escape_htmltag($langs->trans('OptionDescriptionHint')).'">';
				print '</td>';
				print '<td class="opacitymedium small">';
				if ($refs > 0) {
					// Explore by bin: open the warehouse tab pre-filtered to this value
					$explore_url = dol_buildpath('/binloc/tab_warehouse_locations.php', 1).'?id='.$fk_entrepot.'&search_level'.$level_id.'='.$opt->id;
					print '<a href="'.$explore_url.'" title="'.dol_escape_htmltag($langs->trans('ShowLocationsUsingValue')).'">'.$langs->trans('UsedByNLocations', $refs).'</a>';
				} else {
					print $langs->trans('UsedByNLocations', max(0, $refs));
				}
				print '</td>';
				print '<td class="center nowraponall">';
				print '<button type="submit" name="toggleoption" value="'.$opt->id.'" class="button smallpaddingimp">'.($opt->active ? $langs->trans('Disable') : $langs->trans('Enable')).'</button>';
				if ($refs === 0) {
					print ' <button type="submit" name="deleteoption" value="'.$opt->id.'" class="button smallpaddingimp">'.img_picto($langs->trans('Delete'), 'delete').'</button>';
				}
				print '</td>';
				print '</tr>';
			}

			// New-value row for this level
			print '<tr class="oddeven">';
			print '<td>';
			print '<input type="text" name="new_value['.$level_id.']" class="flat width100" placeholder="'.dol_escape_htmltag($langs->trans('NewValue')).'">';
			print ' <input type="text" name="new_desc['.$level_id.']" class="flat minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('OptionDescription')).'" title="'.dol_escape_htmltag($langs->trans('OptionDescriptionHint')).'">';
			print '</td>';
			print '<td class="opacitymedium small">'.$langs->trans('NewValueSavedWithForm').'</td>';
			print '<td></td>';
			print '</tr>';

			print '</table>';
			print '</div>';
		}
	}

	// Universal save: one button for level rows, option edits and new values
	print '<div class="margintoponly">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
	print '</div>';

	print '</form>';

	// ---- JS for dynamic level rows (visual numbering only — identity is the hidden rowid) ----
	$level_hints_json = json_encode(array(
		1 => $langs->trans('LevelHint1'),
		2 => $langs->trans('LevelHint2'),
		3 => $langs->trans('LevelHint3'),
		4 => $langs->trans('LevelHint4'),
		5 => $langs->trans('LevelHint5'),
		6 => $langs->trans('LevelHint6'),
	));

	print '<script>
jQuery(function ($) {
	var hints = '.$level_hints_json.';
	var $table = $("#binloc-level-table tbody");

	function renumber() {
		$table.find(".binloc-level-row").each(function (idx) {
			$(this).find(".binloc-level-num").text(idx + 1);
			var $label = $(this).find("input[name=\'labels[]\']");
			if (!$label.val() && hints[idx + 1]) { $label.attr("placeholder", hints[idx + 1]); }
		});
	}

	$("#binloc-add-level").on("click", function (e) {
		e.preventDefault();
		var tpl = document.getElementById("binloc-level-row-template");
		$table.append($(tpl.content.firstElementChild).clone());
		renumber();
		$table.find(".binloc-level-row:last input[name=\'labels[]\']").focus();
	});

	$table.on("click", ".binloc-remove-level", function (e) {
		e.preventDefault();
		$(this).closest("tr").remove();
		renumber();
	});

	$table.on("click", ".binloc-move-up", function (e) {
		e.preventDefault();
		var $row = $(this).closest("tr");
		var $prev = $row.prevAll(".binloc-level-row").first();
		if ($prev.length) { $row.insertBefore($prev); renumber(); }
	});

	$table.on("click", ".binloc-move-down", function (e) {
		e.preventDefault();
		var $row = $(this).closest("tr");
		var $next = $row.nextAll(".binloc-level-row").first();
		if ($next.length) { $row.insertAfter($next); renumber(); }
	});

	renumber();
});
</script>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
