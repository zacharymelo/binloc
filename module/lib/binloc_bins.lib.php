<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/binloc_bins.lib.php
 * \ingroup binloc
 * \brief   The bin model: derive bins from assignments, enumerate bins from
 *          configuration, and describe a warehouse's level chain
 *
 * Bins are NOT records. A bin is a distinct combination of level values, and
 * "which level a label is stuck on" is the only degree of freedom that
 * matters: a label at level L is identified by the path from the top level
 * down to L; everything assigned below that path is its contents, grouped
 * by the next level down.
 *
 * This file is the stable seam for anything that needs to reason about bins
 * rather than assignments — the labels page today, a directed-putaway /
 * bin-registry extension tomorrow (see docs/WMS-EXTENSION.md at the repo
 * root). Keep it free of HTML and of page state.
 *
 * Shapes used throughout:
 *   value entry  stdClass {fk_option (int|null), value (string|null),
 *                display (string), description (string)}
 *                — the same shape BinlocProductLocation::$values uses
 *   values row   stdClass {values: level rowid => value entry, plus for
 *                assignments: product_ref, product_label, lot_batch}
 *   bin          see binloc_bins_from_rows()
 */

dol_include_once('/binloc/lib/binloc.lib.php');

/**
 * Level rowids of a warehouse in position order (top level first)
 *
 * @param  array $wh_levels Level configs keyed by rowid (fetchByWarehouse output)
 * @return int[]
 */
function binloc_level_chain($wh_levels)
{
	return array_map('intval', array_keys($wh_levels));
}

/**
 * The level that follows $level_id in the chain, or 0 when it is the deepest
 *
 * @param  array $wh_levels Level configs keyed by rowid
 * @param  int   $level_id  Level rowid
 * @return int
 */
function binloc_next_level($wh_levels, $level_id)
{
	$chain = binloc_level_chain($wh_levels);
	$pos = array_search((int) $level_id, $chain, true);
	return ($pos !== false && isset($chain[$pos + 1])) ? $chain[$pos + 1] : 0;
}

/**
 * Default label level for a warehouse: the second-deepest level (a label on
 * the parent, contents grouped by the deepest), or the only level.
 *
 * A pre-2.9 stored "sub_level" is honoured when given: >0 means "group by
 * that level", so the label level is the one above it; -1 meant "no split",
 * so the label level is the deepest.
 *
 * @param  array $wh_levels        Level configs keyed by rowid
 * @param  int   $legacy_sub_level Stored sub_level from an old layout, 0 if none
 * @return int                     Level rowid, 0 when the warehouse has no levels
 */
function binloc_default_label_level($wh_levels, $legacy_sub_level = 0)
{
	$chain = binloc_level_chain($wh_levels);
	if (empty($chain)) {
		return 0;
	}
	if ($legacy_sub_level === -1) {
		return end($chain);
	}
	if ($legacy_sub_level > 0) {
		$pos = array_search((int) $legacy_sub_level, $chain, true);
		if ($pos !== false) {
			return $chain[max(0, $pos - 1)];
		}
	}
	return (count($chain) >= 2) ? $chain[count($chain) - 2] : $chain[0];
}

/**
 * Identity fragment of one value: option rowid for option-backed levels
 * (renames don't change identity), lowercase text otherwise
 *
 * @param  stdClass $entry Value entry
 * @return string
 */
function binloc_value_key($entry)
{
	return !empty($entry->fk_option) ? 'o'.(int) $entry->fk_option : 'v'.dol_strtolower((string) $entry->display);
}

/**
 * True when a value entry carries something displayable
 *
 * @param  stdClass|null $entry Value entry or null
 * @return bool
 */
function binloc_value_present($entry)
{
	return $entry !== null && isset($entry->display) && $entry->display !== null && $entry->display !== '';
}

/**
 * Group value rows into bins at a given label level.
 *
 * Each bin: stdClass {
 *   key             string, canonical path identity
 *   code            string, level values from the top down to the label
 *                   level, joined with $code_sep
 *   own_value       string, the label level's own value ('' if the row set
 *                   stops above it)
 *   own_description string, that value's description ('' if none)
 *   items           array of {ref, label, batch, subpath} assigned directly
 *                   (no value at the grouping level)
 *   subbins         array of {key, title, value, description, items},
 *                   grouped by the next level down, natural-sorted
 *   is_empty        bool, no assignment anywhere under this path
 * }
 * Item subpath = displays of the levels below the grouping level, so a shelf
 * label lists "B3 · PART-1" rather than nesting further.
 *
 * Rows without any value down to the label level are skipped (not a bin).
 * A row whose values stop above the label level keys on what it has, so a
 * product slotted only to a shelf still appears when printing bag tags.
 *
 * @param  array  $rows           Value rows (assignments and/or enumerated bins)
 * @param  array  $wh_levels      Level configs keyed by rowid
 * @param  int    $label_level_id Level the labels are stuck on
 * @param  string $code_sep       Separator between code parts
 * @return array                  Bins, natural-sorted by code
 */
function binloc_bins_from_rows($rows, $wh_levels, $label_level_id, $code_sep = '')
{
	$chain = binloc_level_chain($wh_levels);
	$label_pos = array_search((int) $label_level_id, $chain, true);
	if ($label_pos === false) {
		return array();
	}
	$path_levels = array_slice($chain, 0, $label_pos + 1);
	$group_level = isset($chain[$label_pos + 1]) ? $chain[$label_pos + 1] : 0;
	$below_group = $group_level ? array_slice($chain, $label_pos + 2) : array();

	$bins = array();

	foreach ($rows as $row) {
		$key_parts = array();
		$code_parts = array();
		$own = null;
		foreach ($path_levels as $lid) {
			$entry = isset($row->values[$lid]) ? $row->values[$lid] : null;
			if (!binloc_value_present($entry)) {
				continue;
			}
			$key_parts[] = $lid.':'.binloc_value_key($entry);
			$code_parts[] = $entry->display;
			if ($lid === (int) $label_level_id) {
				$own = $entry;
			}
		}
		if (empty($key_parts)) {
			continue;
		}

		$key = implode('|', $key_parts);
		if (!isset($bins[$key])) {
			$bin = new stdClass();
			$bin->key             = $key;
			$bin->code            = implode($code_sep, $code_parts);
			$bin->own_value       = $own ? $own->display : '';
			$bin->own_description = ($own && !empty($own->description)) ? $own->description : '';
			$bin->items           = array();
			$bin->subbins         = array();
			$bin->is_empty        = true;
			$bins[$key] = $bin;
		}

		if (!isset($row->product_ref)) {
			continue; // enumerated bin: identity only
		}
		$bins[$key]->is_empty = false;

		$item = new stdClass();
		$item->ref   = $row->product_ref;
		$item->label = isset($row->product_label) ? $row->product_label : '';
		$item->batch = !empty($row->lot_batch) ? $row->lot_batch : '';
		$sub_parts = array();
		foreach ($below_group as $lid) {
			$entry = isset($row->values[$lid]) ? $row->values[$lid] : null;
			if (binloc_value_present($entry)) {
				$sub_parts[] = $entry->display;
			}
		}
		$item->subpath = implode($code_sep, $sub_parts);

		$group_entry = ($group_level && isset($row->values[$group_level])) ? $row->values[$group_level] : null;
		if (binloc_value_present($group_entry)) {
			$sub_key = binloc_value_key($group_entry);
			if (!isset($bins[$key]->subbins[$sub_key])) {
				$sub = new stdClass();
				$sub->key         = $sub_key;
				$sub->title       = $wh_levels[$group_level]->label.' '.$group_entry->display;
				$sub->value       = $group_entry->display;
				$sub->description = !empty($group_entry->description) ? $group_entry->description : '';
				$sub->items       = array();
				$bins[$key]->subbins[$sub_key] = $sub;
			}
			$bins[$key]->subbins[$sub_key]->items[] = $item;
		} else {
			$bins[$key]->items[] = $item;
		}
	}

	$bins = array_values($bins);
	foreach ($bins as $bin) {
		$bin->subbins = array_values($bin->subbins);
		usort($bin->subbins, function ($a, $b) {
			return strnatcasecmp($a->value, $b->value);
		});
	}
	usort($bins, function ($a, $b) {
		return strnatcasecmp($a->code, $b->code);
	});
	return $bins;
}

/**
 * Enumerate every bin that the configuration allows down to a label level:
 * the cartesian product of, per level, either the one fixed value (from a
 * filter) or all active options. Levels that are free text/number cannot be
 * enumerated unless fixed; when one is left open the result is empty and
 * its label is reported in 'unenumerable'.
 *
 * Returns array(
 *   'rows'         => value rows {values} without product fields,
 *   'unenumerable' => string[] labels of open text/number levels,
 *   'truncated'    => bool, true when $max was hit
 * )
 *
 * @param  array $wh_levels      Level configs keyed by rowid
 * @param  int   $label_level_id Level to enumerate down to
 * @param  array $fixed          level rowid => raw filter value (option rowid
 *                               for option-backed levels, text otherwise)
 * @param  int   $max            Hard cap on generated rows
 * @return array
 */
function binloc_enumerate_bins($wh_levels, $label_level_id, $fixed = array(), $max = 1000)
{
	$result = array('rows' => array(), 'unenumerable' => array(), 'truncated' => false);

	$chain = binloc_level_chain($wh_levels);
	$label_pos = array_search((int) $label_level_id, $chain, true);
	if ($label_pos === false) {
		return $result;
	}

	$candidates = array(); // level rowid => value entries
	foreach (array_slice($chain, 0, $label_pos + 1) as $lid) {
		$cfg = $wh_levels[$lid];
		$raw = isset($fixed[$lid]) ? trim((string) $fixed[$lid]) : '';
		$entries = array();

		if (binloc_datatype_has_options($cfg->datatype)) {
			foreach ($cfg->options as $opt) {
				if ($raw !== '' ? ((int) $opt->id !== (int) $raw) : !$opt->active) {
					continue;
				}
				$entry = new stdClass();
				$entry->fk_option   = (int) $opt->id;
				$entry->value       = null;
				$entry->display     = $opt->value;
				$entry->description = (string) $opt->description;
				$entries[] = $entry;
			}
		} elseif ($raw !== '') {
			$entry = new stdClass();
			$entry->fk_option   = null;
			$entry->value       = $raw;
			$entry->display     = $raw;
			$entry->description = '';
			$entries[] = $entry;
		} else {
			$result['unenumerable'][] = $cfg->label;
		}
		$candidates[$lid] = $entries;
	}

	if (!empty($result['unenumerable'])) {
		return $result;
	}

	// Cartesian product, capped
	$paths = array(array());
	foreach ($candidates as $lid => $entries) {
		$next = array();
		foreach ($paths as $path) {
			foreach ($entries as $entry) {
				if (count($next) >= $max) {
					$result['truncated'] = true;
					break 2;
				}
				$next[] = $path + array($lid => $entry);
			}
		}
		$paths = $next;
		if (empty($paths)) {
			break;
		}
	}

	foreach ($paths as $path) {
		$row = new stdClass();
		$row->values = $path;
		$result['rows'][] = $row;
	}
	return $result;
}
