# Changelog

## [2.9.0] - 2026-09-12

### Added
- **Labels for any level** — "Print labels for: [level]" on the Bin Labels page. A label at a level is identified by the path from the top down to it (the full code, identical to the system's location code), and lists everything assigned below it grouped by the next level down; deeper sub-paths print compactly on each item (`B3 · PART-1`). A tag per shelf, per bag, per rack — same page, one dropdown.
- **Corner tag** — the label's own level value, top-right, with its own point size (default 18 pt, 0 hides it) so it can be sized for reading distance independently of the body text (rule of thumb: character height in cm ≈ distance in cm ÷ 200).
- **Value description on the label** — the own-level value's description ("Left Rack") prints under the title, in the space the "Contents" header used to take. Toggle per layout.
- **Empty bins** — "Include empty bins" enumerates every configured value combination down to the label level (dropdown/letter levels; fix a free-text level with its filter to enumerate below it) so not-yet-stocked shelving gets tags. Transient: nothing is stored. Capped, with a notice when the cap or an un-enumerable level stops it.
- **Per-level layouts** — the layout panel edits the layout for the selected level; "Save for <level>" stores an override, "Save as warehouse default" the fallback, "Use warehouse default" removes the override.
- **Developer seam** — `lib/binloc_bins.lib.php` holds the bin model (grouping, enumeration, canonical bin identity) for the future WMS extension; see `docs/WMS-EXTENSION.md`.

### Changed
- The "Contents" header is gone from labels.
- The "sub-bin level" setting is retired — grouping is always the next level down from the label level. A previously stored value is honoured as the default label level.

## [2.8.0] - 2026-09-09

### Fixed
- **Critical: "Save All" on the bulk assignment table (and any bulk-table page) returned a 500 error for every save, regardless of warehouse or level type.** Introduced in 2.7.0: `BinlocProductLocation::setRawValue()` started calling a helper defined in `lib/binloc.lib.php`, but `ajax/batch_save.php` never loaded that file — a fatal "call to undefined function" before any row was processed. The single-item quick-assign path was unaffected because it already loaded the file. Fixed by including it where it's used, and — to close this class of bug for good — `binlocproductlocation.class.php` and `lib/binloc_csv.lib.php` now load their own dependency instead of relying on whichever page happens to include them first.

### Added
- **CSV import/export understands letter ranges.** For a `letter`-type level, put a single end code in the `allowed_values` cell (e.g. `Z`, or `AC` to go past Z) to generate the whole A..end range from one row — exactly what the "Generate" button does, but usable from a spreadsheet. A pipe-separated list (`A|C|E`) is still taken literally, for both letter and dropdown levels. Layout export is symmetric: a level whose active values are a clean, gapless A..end sequence exports as the compact end-code shorthand instead of spelling out 26+ values, so an exported file both round-trips and doubles as a readable example of the syntax; any gap or reordering falls back to the full explicit list.

## [2.7.0] - 2026-09-09

### Added
- **Letter level type** ("Letter (A-Z)" alongside Text/Number/Dropdown on Warehouse Levels): a new datatype for levels whose values are letter codes. A "Generate letters up to:" control creates any missing codes from A up to the requested end in one step — a single letter (e.g. `Z`) generates A-Z, a double letter (e.g. `AC`) extends past Z into AA, AB, AC. Existing codes are never touched or duplicated, so the range can be extended later. Generated letters are ordinary values underneath — each still gets its own optional description (e.g. "AC = Cold aisle overflow"), rename, disable, and delete-if-unused, and Generate is a named submit of the same universal form, so it saves any pending edits first. Letter levels work everywhere a Dropdown level does: bin assignment inputs, warehouse-tab and label filters, the Key legend, bin codes and label sub-bin grouping, and CSV layout/assignment import-export.

## [2.6.0] - 2026-09-09

### Changed
- **Warehouse Levels: add many values without reloads.** Each dropdown level's card has an "Add value" action that appends new value+description rows client-side; pressing Enter in a new-value field starts the next row. Any number of queued values are created together on the one universal Save — setting up a level with dozens of bins is now type-Enter-type-Enter, one Save, one page load.

### Fixed
- Pressing Enter in any field on Warehouse Levels could trigger the first Disable button in the form (the browser's implicit submission picks the first submit button) — toggling a value nobody touched. Enter now performs the plain universal save.

## [2.5.0] - 2026-09-09

### Added
- **Per-warehouse label layout** (collapsible "Label layout" panel on the Bin Labels page, admin right): label width, height (0 = auto), top/right/bottom/left padding, and base font size — all in mm/pt. Top padding doubles as a blank header strip on every label (e.g. 21 mm for slide-in bin holders) without any hardcoded rectangle. Labels print edge to edge with zero gap so sheets are cut with a single slice between labels; rows stretch to equal height. Stored as a per-warehouse constant — no schema change. Defaults: 75 mm wide, auto height, 21/3/3/3 mm padding, 9 pt.
- **More label options** in the same panel: border thickness (0 = none, for pre-cut sticker stock), print-sheet margin, bin-code separator ('' = values joined, e.g. `R5B`; `-` = `R5-B`), sub-bin level choice (automatic deepest / none / a specific level), and toggles for lot/serial batches and product names on label contents.

### Fixed
- **Warehouse Levels: universal Save.** The page is now a single form — level rows, every dropdown value and description, and the per-level "new value" inputs all save with one button. Previously each value row was its own form, so saving one discarded unsaved edits in the others. Disable/Enable/Delete also save all pending edits before applying.

## [2.4.0] - 2026-09-09

### Added
- **Quick assign on Bulk Bin Assignment**: a product picker at the top of the page assigns any product to a bin in the selected warehouse — including products with no stock there yet. Pre-assigned products stay visible in the bulk table (it now lists products with stock *or* a bin assignment).
- **Bin Labels** (new page, read permission): generates one printable label per distinct bin in use, selected by location (per-level filters) or by product (search). Labels show the compact bin code (level values concatenated, e.g. `AL253B`) and the products assigned (quantities ignored). When the warehouse has two or more levels, the deepest level acts as the sub-bin: it stays off the bin code and contents are grouped under one heading per sub-bin value (`Bag 3 (description)`), using the level's configured name. A "Key" legend explains value codes on screen and on the printout. "Print labels" opens a chrome-less view that triggers the browser print dialog.
- **Dropdown value descriptions**: each allowed value can carry a description (value `L`, description `Left Rack`) so bin names stay short without losing meaning. Shown as tooltips on inputs and value cells, in a "Key" legend on the bulk/warehouse/label pages, and on bin labels. New `description` column on `llx_binloc_level_options` (migration step 2.4.0-1 — run from the setup-page banner after a file-only upgrade).

### Fixed
- **List pagination**: the bulk-assign page and warehouse tab never showed next-page navigation (they fetched exactly one page, so Dolibarr's list bar could not detect more rows), and the rows-per-page selector did nothing (it submits its enclosing form, and the list bar was outside any form). Both now paginate correctly, and the chosen page size survives page changes and sorting.

## [2.3.0] - 2026-07-22

### Added
- **In-app user guide**: the full user guide now ships with the module (`docs/USER-GUIDE.md`) and renders inside Dolibarr at `/custom/binloc/help.php`. Every Bin Locations page links to it via Dolibarr's standard **?** help icon in the top bar — so the docs always match the installed version.

## [2.2.0] - 2026-07-21

### Added
- **CSV import/export** (new Import/Export admin tab) for spreadsheet workflows — build the layout in Google Sheets, download as CSV, import. Export output is directly re-importable.
  - *Warehouse layout* format: one row per level (`warehouse;level;label;type;allowed_values` with pipe-separated values). Import matches levels by label, creates new ones, adds missing allowed values — never deletes.
  - *Bin assignments* format (per warehouse): `product;product_label;lot;<one column per level>;note`. Level columns present in the file are authoritative (empty cell clears the level, empty row removes the assignment); rows are upserts on product ref + lot.
  - Imports show a dry-run preview of every planned change (and every error) before anything is written, and apply all-or-nothing in a transaction. Unknown dropdown values are rejected unless the "create missing dropdown values" option is ticked.
  - Export button on the warehouse Bin Locations tab.

## [2.1.0] - 2026-07-21

### Added
- **Explore by bin**: the warehouse Bin Locations tab has per-level filters — dropdowns for list levels, partial-match search for text/number levels. Filters combine, survive sorting and pagination, and can be shared as URLs.
- The "used by N location(s)" count next to each dropdown value on the Warehouse Levels admin page is now a link that opens the warehouse tab pre-filtered to exactly those locations.

## [2.0.0] - 2026-07-21

Major release: normalized data model, safe update mechanics, and an AJAX UI. Existing data is migrated in place by a versioned migration runner (run automatically on module enable, or from the banner on the setup page after a file-only upgrade).

### Changed — data model (breaking, migrated automatically)
- Bin values now live in a normalized child table (`llx_binloc_location_value`), one row per level value, referencing the level and — for dropdown levels — the allowed value by ID. The positional `level1_value…level6_value` columns are dropped after a verified migration.
- Dropdown values moved from the comma-separated `list_values` setting into `llx_binloc_level_options` (per-level rows). **Renaming a value now updates every existing assignment automatically**; values in use cannot be deleted, only disabled.
- Levels have stable identity: editing the level configuration updates rows in place instead of delete-and-reinsert, so reordering or removing a level no longer silently re-labels existing location data. Removed levels that still hold data are deactivated, not destroyed.
- Uniqueness of (product, warehouse, lot) is now enforced by a database unique index (lot "none" is stored as 0 instead of NULL to make this possible). Duplicate rows are merged during migration, keeping the most recent.
- Migration is versioned, resumable, and verified: every legacy value is checked to have a migrated counterpart before the old columns are dropped; failures are reported on the setup page, never swallowed. Orphaned dropdown values and values on deleted levels are preserved as "(legacy)" entries — zero data loss by construction.

### Changed — UI
- Assign, edit, and delete are AJAX operations with CSRF tokens — no more full-page reloads, and switching warehouse in a form no longer discards typed values.
- Edit dropdowns show a stored value that is no longer in the allowed list as a selected "(legacy)" option instead of silently blanking it on save.
- The warehouse tab gained the missing delete action; deletes everywhere are POSTs with confirmation instead of GET links.
- Bulk pages (reception, MO, bulk assign) share one renderer; blank inputs never overwrite stored values, clearing a row is an explicit action, and batch-set asks for confirmation naming the affected fields and row count.
- Warehouse level admin: reorder with up/down, per-value management (rename/disable/delete-if-unused) with usage counts.
- The lot/serial card panel is a proper field row with inline AJAX editing (the old markup hack around Dolibarr's action-button container is gone).
- Theme-aware styles — dark mode no longer breaks on hardcoded colors.

### Fixed
- Zero-stock auto-clear no longer deletes lot/serial location rows along with the product-level row.
- Deleting a lot now cleans up its bin location (new trigger handler).
- The debug endpoint's arbitrary-SQL mode was removed; diagnostics are fixed read-only queries.
- `BINLOC_DEBUG_MODE` is now properly declared by the module descriptor.

## [1.6.2] - 2026-05-29

### Fixed
- Bin location edit form no longer loses saved values when re-saving (fetch was overwriting incoming values before update).

## [1.6.1] - 2026-04-22

### Changed
- Settings page uses Dolibarr's AJAX on/off switch (`ajax_constantonoff`) instead of form-submitted checkboxes — matches the toggle pattern used in the other modules. Values persist immediately; no Save button needed.

## [1.6.0] - 2026-04-15

Initial published release of Binloc.

### Added
- Per-warehouse location hierarchy — each warehouse defines its own level names (Row/Bay/Shelf/Bin, Case/Drawer/Bin, etc.)
- Tabs on product, warehouse, manufacturing order, and reception cards for viewing and assigning locations
- Bulk-assign page for setting locations across many products at once
- Admin setup page for level-name configuration

---

> **History note:** this repository previously hosted a different module called Wareloc (a warehouse-nesting tree builder, versions up to 2.1.2). That codebase was abandoned in favour of Binloc, a ground-up rewrite with a different architecture. The v2.1.3 release tagged against the old Wareloc code was a mistake and has been reverted. If you need the old Wareloc code, check out commit `06f5363`.
