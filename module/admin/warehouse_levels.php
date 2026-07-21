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

/**
 * A level id posted by an option action must belong to the selected warehouse
 *
 * @param  array $levels Current level configs keyed by rowid
 * @param  int   $level_id Posted level rowid
 * @return bool
 */
function binloc_admin_level_belongs($levels, $level_id)
{
	return $level_id > 0 && isset($levels[$level_id]);
}

// ---- ACTIONS ----

$current_levels = ($fk_entrepot > 0) ? $levelObj->fetchByWarehouse($fk_entrepot, true) : array();

if ($action === 'savelevels' && $fk_entrepot > 0) {
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

	$result = $levelObj->applyWarehouseLevels($fk_entrepot, $rows, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('LevelsSaved'), null, 'mesgs');
	} else {
		setEventMessages($levelObj->error, null, 'errors');
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

// Option sub-editor actions
if ($fk_entrepot > 0 && in_array($action, array('addoption', 'renameoption', 'toggleoption', 'deleteoption'), true)) {
	$level_id  = GETPOSTINT('level_id');
	$option_id = GETPOSTINT('option_id');

	if (!binloc_admin_level_belongs($current_levels, $level_id)) {
		setEventMessages('Invalid level', null, 'errors');
	} elseif ($action === 'addoption') {
		$value = GETPOST('option_value', 'alphanohtml');
		$max_pos = 0;
		foreach ($current_levels[$level_id]->options as $opt) {
			$max_pos = max($max_pos, $opt->position);
		}
		$result = $optionObj->create($level_id, $value, $max_pos + 1, $user);
		if ($result > 0) {
			setEventMessages($langs->trans('OptionAdded'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($optionObj->error), null, 'errors');
		}
	} else {
		// Option must belong to the level
		$owned = false;
		foreach ($current_levels[$level_id]->options as $opt) {
			if ((int) $opt->id === $option_id) {
				$owned = true;
				break;
			}
		}
		if (!$owned) {
			setEventMessages('Invalid option', null, 'errors');
		} elseif ($action === 'renameoption') {
			$result = $optionObj->rename($option_id, GETPOST('option_value', 'alphanohtml'), $user);
			if ($result > 0) {
				setEventMessages($langs->trans('OptionRenamed'), null, 'mesgs');
			} else {
				setEventMessages($langs->trans($optionObj->error), null, 'errors');
			}
		} elseif ($action === 'toggleoption') {
			$result = $optionObj->setActive($option_id, GETPOSTINT('active'), $user);
			if ($result <= 0) {
				setEventMessages($langs->trans($optionObj->error), null, 'errors');
			}
		} elseif ($action === 'deleteoption') {
			$result = $optionObj->deleteIfUnreferenced($option_id);
			if ($result > 0) {
				setEventMessages($langs->trans('OptionDeleted'), null, 'mesgs');
			} elseif ($result == -2) {
				setEventMessages($langs->trans('OptionInUseDeactivateInstead', $optionObj->countReferences($option_id)), null, 'warnings');
			} else {
				setEventMessages($langs->trans($optionObj->error), null, 'errors');
			}
		}
	}
	$action = '';
	$current_levels = $levelObj->fetchByWarehouse($fk_entrepot, true);
}

// ---- VIEW ----

$page_name = 'BinlocSetup';
llxHeader('', $langs->trans($page_name), '');

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

	// ---- Level editor (stable rowids, order = row order) ----
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
	print ' <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
	print '</div>';

	print '</form>';

	// ---- Options sub-editor for list-type levels ----
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
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="renameoption">';
				print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
				print '<input type="hidden" name="level_id" value="'.$level_id.'">';
				print '<input type="hidden" name="option_id" value="'.$opt->id.'">';
				print '<input type="text" name="option_value" class="flat width100" value="'.dol_escape_htmltag($opt->value).'">';
				print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Rename')).'">';
				print '</form>';
				print '</td>';
				print '<td class="opacitymedium small">'.$langs->trans('UsedByNLocations', max(0, $refs)).'</td>';
				print '<td class="center nowraponall">';
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="toggleoption">';
				print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
				print '<input type="hidden" name="level_id" value="'.$level_id.'">';
				print '<input type="hidden" name="option_id" value="'.$opt->id.'">';
				print '<input type="hidden" name="active" value="'.($opt->active ? 0 : 1).'">';
				print '<button type="submit" class="button smallpaddingimp">'.($opt->active ? $langs->trans('Disable') : $langs->trans('Enable')).'</button>';
				print '</form> ';
				if ($refs === 0) {
					print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
					print '<input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="deleteoption">';
					print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
					print '<input type="hidden" name="level_id" value="'.$level_id.'">';
					print '<input type="hidden" name="option_id" value="'.$opt->id.'">';
					print '<button type="submit" class="button smallpaddingimp">'.img_picto($langs->trans('Delete'), 'delete').'</button>';
					print '</form>';
				}
				print '</td>';
				print '</tr>';
			}
			print '</table>';

			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="margintoponly">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="addoption">';
			print '<input type="hidden" name="fk_entrepot" value="'.$fk_entrepot.'">';
			print '<input type="hidden" name="level_id" value="'.$level_id.'">';
			print '<input type="text" name="option_value" class="flat width100" placeholder="'.dol_escape_htmltag($langs->trans('NewValue')).'">';
			print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
			print '</form>';

			print '</div>';
		}
	}

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
