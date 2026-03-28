<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    admin/setup.php
 * \ingroup wareloc
 * \brief   Wareloc v2 — level name configuration per root warehouse
 *
 * Defines what to call each depth of the warehouse hierarchy.
 * Each root warehouse can have its own label set (e.g. Toolbox/Drawer/Compartment
 * for Packout vs Row/Shelf/Bin for Main Shelving). Falls back to the Global Default
 * if a warehouse has no custom labels.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php"))  { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/wareloc/lib/wareloc.lib.php');

$langs->loadLangs(array('admin', 'wareloc@wareloc'));

if (!$user->admin) {
	accessforbidden();
}

$action   = GETPOST('action', 'aZ09');
$selected_wh = GETPOSTINT('wh'); // 0 = global default, >0 = specific root warehouse

// ---- ACTIONS ----

if ($action === 'savelabels') {
	$depths = GETPOST('depth', 'array');
	$labels = GETPOST('label', 'array');

	if (is_array($depths) && is_array($labels)) {
		$db->begin();
		$error = 0;

		// Delete existing rows for this context
		$del_sql = "DELETE FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
		$del_sql .= " WHERE entity = ".((int) $conf->entity);
		if ($selected_wh > 0) {
			$del_sql .= " AND fk_entrepot = ".((int) $selected_wh);
		} else {
			$del_sql .= " AND fk_entrepot IS NULL";
		}
		if (!$db->query($del_sql)) {
			$error++;
		}

		if (!$error) {
			foreach ($depths as $i => $depth) {
				$depth = (int) $depth;
				$label = trim($labels[$i] ?? '');
				if ($depth <= 0 || $label === '') {
					continue;
				}
				$ins_sql = "INSERT INTO ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
				$ins_sql .= " (entity, fk_entrepot, depth, label, date_creation, fk_user_creat)";
				$ins_sql .= " VALUES (";
				$ins_sql .= ((int) $conf->entity);
				$ins_sql .= ", ".($selected_wh > 0 ? ((int) $selected_wh) : "NULL");
				$ins_sql .= ", ".$depth;
				$ins_sql .= ", '".$db->escape($label)."'";
				$ins_sql .= ", '".$db->idate(dol_now())."'";
				$ins_sql .= ", ".((int) $user->id);
				$ins_sql .= ")";
				if (!$db->query($ins_sql)) {
					$error++;
					break;
				}
			}
		}

		if ($error) {
			$db->rollback();
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('LabelsSaved'), null, 'mesgs');
		}
	}
	$action = '';
}

if ($action === 'copyfromglobal' && $selected_wh > 0) {
	// Copy global labels to this warehouse
	$sql = "SELECT depth, label FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_entrepot IS NULL";
	$sql .= " ORDER BY depth ASC";
	$resql = $db->query($sql);
	if ($resql) {
		$db->begin();
		$error = 0;

		// Remove existing warehouse-specific rows first
		$del = "DELETE FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
		$del .= " WHERE entity = ".((int) $conf->entity)." AND fk_entrepot = ".((int) $selected_wh);
		if (!$db->query($del)) {
			$error++;
		}

		while (!$error && ($obj = $db->fetch_object($resql))) {
			$ins = "INSERT INTO ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
			$ins .= " (entity, fk_entrepot, depth, label, date_creation, fk_user_creat)";
			$ins .= " VALUES (".((int) $conf->entity).", ".((int) $selected_wh).", ".((int) $obj->depth).", '".$db->escape($obj->label)."', '".$db->idate(dol_now())."', ".((int) $user->id).")";
			if (!$db->query($ins)) {
				$error++;
			}
		}
		$db->free($resql);

		if ($error) {
			$db->rollback();
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('LabelsCopiedFromGlobal'), null, 'mesgs');
		}
	}
	$action = '';
}

if ($action === 'resettodefault' && $selected_wh > 0) {
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."wareloc_hierarchy_meta";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_entrepot = ".((int) $selected_wh);
	if ($db->query($sql)) {
		setEventMessages($langs->trans('LabelsResetToGlobal'), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	$action = '';
}

// ---- VIEW ----

llxHeader('', $langs->trans('WarelocSetup'), '');

$form = new Form($db);
$head = wareloc_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('WarelocSetup'), -1, 'stock');

// ---- Warehouse selector ----

$root_warehouses = wareloc_get_root_warehouses($db);

print '<div class="marginbottomonly">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
print $form->textwithpicto('<strong>'.$langs->trans('Warehouse').'</strong>', $langs->trans('LevelNamesWarehouseSelectorDesc')).' ';
print '<select name="wh" class="flat minwidth200" onchange="this.form.submit()">';
print '<option value="0"'.($selected_wh == 0 ? ' selected' : '').'>'.$langs->trans('GlobalDefault').'</option>';
foreach ($root_warehouses as $wh) {
	$has_custom = wareloc_warehouse_has_label_overrides($wh->rowid, $db);
	$sel = ($selected_wh == $wh->rowid) ? ' selected' : '';
	print '<option value="'.$wh->rowid.'"'.$sel.'>'.dol_escape_htmltag($wh->ref).($has_custom ? ' *' : '').'</option>';
}
print '</select>';
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Select').'">';
print '</form>';
print ' <span class="opacitymedium small">'.$langs->trans('LevelNamesOverrideHint').'</span>';
print '</div>';

// ---- Current labels for this context ----

$current_labels = wareloc_get_depth_labels($db, $selected_wh);
$is_inherited   = ($selected_wh > 0 && !wareloc_warehouse_has_label_overrides($selected_wh, $db));

if ($is_inherited) {
	print '<div class="info marginbottomonly">';
	print $langs->trans('LevelNamesUsingGlobal');
	print ' <a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=copyfromglobal&wh='.$selected_wh.'&token='.newToken().'" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmCopyFromGlobal')).'\')">';
	print $langs->trans('CopyFromGlobal');
	print '</a>';
	print '</div>';
}

if ($selected_wh > 0 && !$is_inherited) {
	print '<div class="marginbottomonly">';
	print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=resettodefault&wh='.$selected_wh.'&token='.newToken().'" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmResetToGlobal')).'\')">';
	print $langs->trans('ResetToGlobal');
	print '</a>';
	print ' <span class="opacitymedium small">'.$langs->trans('ResetToGlobalDesc').'</span>';
	print '</div>';
}

// ---- Labels form ----

print load_fiche_titre($langs->trans('DepthLabelConfig'), '', '');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('DepthLabelConfigDesc').'</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savelabels">';
if ($selected_wh > 0) {
	print '<input type="hidden" name="wh" value="'.$selected_wh.'">';
}

print '<table class="noborder centpercent" id="wareloc-labels-table">';
print '<tr class="liste_titre">';
print '<td style="width:80px">'.$form->textwithpicto($langs->trans('Depth'), $langs->trans('DepthDesc')).'</td>';
print '<td>'.$form->textwithpicto($langs->trans('LevelLabel'), $langs->trans('LevelLabelInputDesc')).'</td>';
print '<td style="width:60px"></td>';
print '</tr>';

// Render existing rows; pad to at least 3, cap display at 8
$max_depths = 8;
$row_count  = max(count($current_labels), 3);
$row_count  = min($row_count, $max_depths);

for ($d = 1; $d <= $row_count; $d++) {
	$val = isset($current_labels[$d]) ? dol_escape_htmltag($current_labels[$d]) : '';
	print '<tr class="oddeven wareloc-label-row" id="wareloc-label-row-'.$d.'">';
	print '<td class="center opacitymedium">'.$d.'<input type="hidden" name="depth[]" value="'.$d.'"></td>';
	$placeholder = $langs->trans('DepthLabelPlaceholder'.$d) ?: $langs->trans('DepthLabelPlaceholderDefault');
	print '<td><input type="text" name="label[]" class="flat minwidth200" value="'.$val.'" placeholder="'.dol_escape_htmltag($placeholder).'" autocomplete="off"></td>';
	print '<td class="center">';
	if ($d > 1) {
		print '<a href="#" onclick="warelocRemoveDepthRow('.$d.'); return false;" title="'.dol_escape_htmltag($langs->trans('RemoveDepth')).'">'.img_picto('', 'delete', 'class="pictofixedwidth"').'</a>';
	}
	print '</td>';
	print '</tr>';
}

print '</table>';

print '<div id="wareloc-depth-count" style="display:none">'.$row_count.'</div>';
print '<div id="wareloc-max-depths" style="display:none">'.$max_depths.'</div>';

print '<div class="marginbottomonly margintoponly">';
print '<a href="#" class="button smallpaddingimp" id="wareloc-add-depth" onclick="warelocAddDepthRow(); return false;">'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('AddDepth').'</a>';
print '</div>';

print '<div class="tabsAction">';
print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

// ---- JS for add/remove depth rows ----

print '<script>
var warelocDepthCount = parseInt(document.getElementById("wareloc-depth-count").textContent);
var warelocMaxDepths  = parseInt(document.getElementById("wareloc-max-depths").textContent);
var warelocDepthPlaceholders = '.json_encode(array_map(function ($d) use ($langs) {
	$key = 'DepthLabelPlaceholder'.$d;
	$val = $langs->trans($key);
	return ($val === $key) ? $langs->trans('DepthLabelPlaceholderDefault') : $val;
}, range(1, 8))).';

function warelocAddDepthRow() {
	if (warelocDepthCount >= warelocMaxDepths) {
		alert("'.$langs->trans('MaxDepthsReached').'");
		return;
	}
	warelocDepthCount++;
	var d    = warelocDepthCount;
	var tbody = document.querySelector("#wareloc-labels-table tbody") || document.querySelector("#wareloc-labels-table");
	var addBtn = document.getElementById("wareloc-add-depth");
	var row  = document.createElement("tr");
	row.className = "oddeven wareloc-label-row";
	row.id = "wareloc-label-row-" + d;
	var ph = warelocDepthPlaceholders[d - 1] || "";
	row.innerHTML =
		"<td class=\"center opacitymedium\">" + d + "<input type=\"hidden\" name=\"depth[]\" value=\"" + d + "\"></td>" +
		"<td><input type=\"text\" name=\"label[]\" class=\"flat minwidth200\" placeholder=\"" + ph + "\" autocomplete=\"off\"></td>" +
		"<td class=\"center\"><a href=\"#\" onclick=\"warelocRemoveDepthRow(" + d + "); return false;\" title=\"'.dol_escape_js($langs->trans('RemoveDepth')).'\">' +
			document.querySelector(".fa-trash, .fa-times, [alt=delete]")?.outerHTML || "×" +
		"</a></td>";
	addBtn.parentNode.before(row);
	row.querySelector("input[type=text]").focus();
	document.getElementById("wareloc-add-depth").style.display = (warelocDepthCount >= warelocMaxDepths) ? "none" : "";
}

function warelocRemoveDepthRow(d) {
	var row = document.getElementById("wareloc-label-row-" + d);
	if (row) {
		row.remove();
		warelocDepthCount--;
		document.getElementById("wareloc-add-depth").style.display = "";
	}
}
</script>';

llxFooter();
$db->close();
