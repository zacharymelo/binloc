/* Copyright (C) 2026 Zachary Melo */
/* Binloc shared front-end. The single home of all Binloc page behavior —
 * level-input fragments are always rendered server-side (levels_get.php);
 * this file only swaps them in and wires events. */

window.Binloc = (function ($) {
	'use strict';

	var cfg = { ajaxBase: '', token: '' };
	var levelsCache = {}; // "whId|prefix" -> response (templates only, no loc prefill)

	function init(options) {
		cfg.ajaxBase = options.ajaxBase || '';
		cfg.token = options.token || '';
	}

	function toast(msg, isError) {
		if ($.jnotify) {
			$.jnotify(msg, isError ? 'error' : 'ok');
		} else {
			window.alert(msg);
		}
	}

	// ---- transport ----------------------------------------------------

	function get(endpoint, params, cb) {
		$.getJSON(cfg.ajaxBase + endpoint, params)
			.done(function (data) { cb(null, data); })
			.fail(function (xhr) { cb(errMsg(xhr), null); });
	}

	function post(endpoint, params, cb) {
		params = $.extend({ token: cfg.token }, params);
		$.post(cfg.ajaxBase + endpoint, params, null, 'json')
			.done(function (data) { cb(null, data); })
			.fail(function (xhr) { cb(errMsg(xhr), null); });
	}

	function postJson(endpoint, body, cb) {
		body.token = cfg.token;
		// Token also goes in the query string: Dolibarr's core CSRF check
		// cannot read a JSON body, only GET/POST params.
		$.ajax({
			url: cfg.ajaxBase + endpoint + '?token=' + encodeURIComponent(cfg.token),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify(body),
			dataType: 'json'
		})
			.done(function (data) { cb(null, data); })
			.fail(function (xhr) { cb(errMsg(xhr), null); });
	}

	function errMsg(xhr) {
		try {
			var data = JSON.parse(xhr.responseText);
			if (data && data.error) { return data.error; }
		} catch (e) { /* fall through */ }
		return 'Request failed (' + xhr.status + ')';
	}

	// ---- level inputs -------------------------------------------------

	function fetchLevels(whId, prefix, locId, cb) {
		var cacheKey = whId + '|' + (prefix || '');
		if (!locId && levelsCache[cacheKey]) {
			cb(null, levelsCache[cacheKey]);
			return;
		}
		get('levels_get.php', { fk_entrepot: whId, prefix: prefix || '', loc_id: locId || 0 }, function (err, data) {
			if (!err && !locId) { levelsCache[cacheKey] = data; }
			cb(err, data);
		});
	}

	function collectLevelValues($container, prefix) {
		var values = {};
		$container.find('.binloc-level-input').each(function () {
			var name = $(this).attr('name') || '';
			var expected = (prefix || '') + 'binloc_level';
			if (name.indexOf(expected) === 0) {
				values['binloc_level' + $(this).data('level')] = $(this).val();
			}
		});
		return values;
	}

	// Swap the level inputs of a container to another warehouse's set,
	// preserving typed values for levels the two warehouses share.
	function swapLevelInputs($container, whId, prefix, locId) {
		var previous = {};
		$container.find('.binloc-level-input').each(function () {
			previous[$(this).data('level')] = $(this).val();
		});
		if (!whId) {
			$container.empty();
			return;
		}
		fetchLevels(whId, prefix, locId, function (err, data) {
			if (err) { toast(err, true); return; }
			$container.html(data.html);
			$container.find('.binloc-level-input').each(function () {
				var lvl = $(this).data('level');
				if (previous[lvl] !== undefined && previous[lvl] !== '' && !$(this).val()) {
					$(this).val(previous[lvl]);
				}
			});
		});
	}

	// ---- mutations ----------------------------------------------------

	function saveLocation(params, cb) {
		post('location_save.php', params, function (err, data) {
			if (err) { toast(err, true); if (cb) { cb(err, null); } return; }
			toast(cfg.msgSaved || 'Saved');
			if (cb) { cb(null, data); }
		});
	}

	function deleteLocation(locId, confirmMessage, cb) {
		if (confirmMessage && !window.confirm(confirmMessage)) {
			return;
		}
		post('location_delete.php', { loc_id: locId }, function (err, data) {
			if (err) { toast(err, true); if (cb) { cb(err, null); } return; }
			toast(cfg.msgRemoved || 'Removed');
			if (cb) { cb(null, data); }
		});
	}

	function batchSave(rows, cb) {
		postJson('batch_save.php', { rows: rows }, cb);
	}

	// ---- inline edit --------------------------------------------------
	// Rows carry: data-loc-id, data-fk-product, data-fk-entrepot, data-fk-lot.
	// The formatted location lives in .binloc-loc-display; the edit form is
	// injected into .binloc-loc-cell.

	function bindInlineEdit($scope, texts) {
		texts = texts || {};

		$scope.on('click', '.binloc-edit-btn', function (e) {
			e.preventDefault();
			var $row = $(this).closest('[data-fk-product]');
			openInlineEdit($row, texts);
		});

		$scope.on('click', '.binloc-delete-btn', function (e) {
			e.preventDefault();
			var $row = $(this).closest('[data-fk-product]');
			var locId = $row.data('loc-id');
			if (!locId) { return; }
			deleteLocation(locId, texts.confirmDelete || 'Remove this bin location?', function () {
				if ($row.hasClass('binloc-card')) {
					$row.remove();
				} else {
					$row.find('.binloc-loc-display').text('');
					$row.data('loc-id', 0).attr('data-loc-id', 0);
					$row.find('.binloc-delete-btn').hide();
				}
			});
		});
	}

	function openInlineEdit($row, texts) {
		var $cell = $row.find('.binloc-loc-cell');
		if ($cell.find('.binloc-inline-form').length) {
			return; // already editing
		}
		var whId = $row.data('fk-entrepot');
		var locId = $row.data('loc-id') || 0;
		var prefix = 'edit' + ($row.data('fk-product')) + '_';

		fetchLevels(whId, prefix, locId, function (err, data) {
			if (err) { toast(err, true); return; }
			var $display = $cell.find('.binloc-loc-display');
			var $form = $('<div class="binloc-inline-form"></div>');
			$form.append(data.html);
			var $note = $('<input type="text" class="flat minwidth150 binloc-note-input">')
				.attr('placeholder', texts.notePlaceholder || 'Note')
				.val($row.data('note') || '');
			var $save = $('<button type="button" class="button small binloc-inline-save"></button>').text(texts.save || 'Save');
			var $cancel = $('<button type="button" class="button button-cancel small binloc-inline-cancel"></button>').text(texts.cancel || 'Cancel');
			$form.append($note).append($save).append($cancel);
			$display.hide();
			$cell.append($form);

			$cancel.on('click', function () {
				$form.remove();
				$display.show();
			});
			$save.on('click', function () {
				var params = $.extend({
					fk_product: $row.data('fk-product'),
					fk_entrepot: whId,
					fk_product_lot: $row.data('fk-lot') || 0,
					note: $note.val()
				}, collectLevelValues($form, prefix));
				saveLocation(params, function (err2, resp) {
					if (err2) { return; }
					$display.text(resp.formatted).show();
					$row.data('loc-id', resp.id).attr('data-loc-id', resp.id);
					$row.data('note', $note.val());
					$row.find('.binloc-delete-btn').show();
					$form.remove();
					$row.trigger('binloc:saved', resp);
				});
			});
		});
	}

	// ---- bulk helpers -------------------------------------------------

	// Fill a level column down: copy the topmost non-empty input of that level
	// into every empty input below it. Client-side only until Save.
	function fillDown($table, levelId) {
		var source = null;
		$table.find('.binloc-level-input').filter(function () {
			return String($(this).data('level')) === String(levelId);
		}).each(function () {
			var val = $(this).val();
			if (source === null && val !== '') {
				source = val;
			} else if (source !== null && val === '') {
				$(this).val(source);
			}
		});
	}

	// Batch-set panel: apply the panel's non-empty inputs to checked rows after
	// an explicit confirmation naming the fields and the row count.
	function applyBatchPanel($panel, $table, texts) {
		texts = texts || {};
		var fields = [];
		$panel.find('.binloc-level-input').each(function () {
			if ($(this).val() !== '') {
				fields.push({
					level: $(this).data('level'),
					value: $(this).val(),
					label: $(this).attr('placeholder') || $(this).attr('aria-label') || ('Level ' + $(this).data('level'))
				});
			}
		});
		var $checked = $table.find('.binloc-row-check:checked');
		if (!fields.length || !$checked.length) {
			toast(texts.nothingToApply || 'Nothing to apply', true);
			return;
		}
		var names = fields.map(function (f) { return f.label; }).join(', ');
		var msg = (texts.confirmBatch || 'Set %fields% on %count% row(s)?')
			.replace('%fields%', names)
			.replace('%count%', $checked.length);
		if (!window.confirm(msg)) {
			return;
		}
		$checked.each(function () {
			var $row = $(this).closest('tr');
			fields.forEach(function (f) {
				$row.find('.binloc-level-input').filter(function () {
					return String($(this).data('level')) === String(f.level);
				}).val(f.value);
			});
		});
	}

	// ---- bulk table (shared by reception tab, MO tab, bulk assign) ----

	function bindBulkTable($table, texts) {
		texts = texts || {};

		$table.on('change', '.binloc-wh-select', function () {
			var $row = $(this).closest('tr');
			swapLevelInputs($row.find('.binloc-levels-container'), $(this).val(), $row.data('prefix'), 0);
			$row.data('fk-entrepot', $(this).val());
		});

		// Copy this row's level values down into empty inputs of following rows
		$table.on('click', '.binloc-filldown', function (e) {
			e.preventDefault();
			var $row = $(this).closest('tr');
			var source = {};
			$row.find('.binloc-level-input').each(function () {
				if ($(this).val() !== '') { source[$(this).data('level')] = $(this).val(); }
			});
			$row.nextAll('tr.binloc-bulk-row').not('[data-disabled]').each(function () {
				$(this).find('.binloc-level-input').each(function () {
					var lvl = $(this).data('level');
					if (source[lvl] !== undefined && $(this).val() === '') {
						$(this).val(source[lvl]);
					}
				});
			});
		});

		$table.on('click', '.binloc-row-clear', function (e) {
			e.preventDefault();
			var $row = $(this).closest('tr');
			$row.toggleClass('binloc-cleared');
			$row.find('.binloc-level-input, .binloc-note-input').prop('disabled', $row.hasClass('binloc-cleared'));
		});

		$table.on('change', '.binloc-check-all', function () {
			$table.find('.binloc-row-check:not(:disabled)').prop('checked', $(this).is(':checked'));
		});
	}

	function saveBulkTable($table, cb) {
		var rows = [];
		$table.find('tr.binloc-bulk-row').not('[data-disabled]').each(function () {
			var $row = $(this);
			var levels = {};
			var hasValue = false;
			$row.find('.binloc-level-input').each(function () {
				if ($(this).val() !== '') {
					levels[$(this).data('level')] = $(this).val();
					hasValue = true;
				}
			});
			var clear = $row.hasClass('binloc-cleared');
			if (!hasValue && !clear) { return; }
			rows.push({
				key: $row.data('key'),
				fk_product: $row.data('fk-product'),
				fk_entrepot: $row.find('.binloc-wh-select').val() || $row.data('fk-entrepot'),
				fk_product_lot: $row.data('fk-lot') || 0,
				note: $row.find('.binloc-note-input').val() || '',
				levels: levels,
				clear: clear
			});
		});

		if (!rows.length) {
			toast(cfg.msgNothing || 'Nothing to save', true);
			return;
		}

		batchSave(rows, function (err, resp) {
			if (err) { toast(err, true); if (cb) { cb(err, null); } return; }
			var errorKeys = {};
			(resp.errors || []).forEach(function (e) { errorKeys[e.key] = e.error; });
			$table.find('tr.binloc-bulk-row').each(function () {
				var key = $(this).data('key');
				$(this).removeClass('binloc-row-status-ok binloc-row-status-error');
				if (errorKeys[key] !== undefined) {
					$(this).addClass('binloc-row-status-error').attr('title', errorKeys[key]);
				}
			});
			var msg = (cfg.msgBulkSaved || '%s saved').replace('%s', resp.saved + (resp.deleted ? '+' + resp.deleted : ''));
			toast(resp.errors && resp.errors.length ? msg + ' — ' + resp.errors.length + ' error(s)' : msg, resp.errors && resp.errors.length > 0);
			if (cb) { cb(null, resp); }
		});
	}

	return {
		init: init,
		config: cfg,
		bindBulkTable: bindBulkTable,
		saveBulkTable: saveBulkTable,
		toast: toast,
		fetchLevels: fetchLevels,
		swapLevelInputs: swapLevelInputs,
		collectLevelValues: collectLevelValues,
		saveLocation: saveLocation,
		deleteLocation: deleteLocation,
		batchSave: batchSave,
		bindInlineEdit: bindInlineEdit,
		openInlineEdit: openInlineEdit,
		fillDown: fillDown,
		applyBatchPanel: applyBatchPanel
	};
})(jQuery);
