# Changelog

## [2.15.4] - 2026-09-13

### Fixed
- **Place sheets were empty on Dolibarr 22** ("Nothing to pick or place"). The reception query sorted on a line-order column that only exists from Dolibarr 23, so the query failed and returned no lines. Lines now sort in the order they were added.

### Added
- **"Has a bin in: …" on sheet lines without a bin** in their warehouse. When the product has bins in other warehouses, those warehouses are named, so "No bin assigned" isn't read as "this product has no bin anywhere".

## [2.15.3] - 2026-09-13

### Fixed
- **Place sheet button no longer returns HTTP 500.** Loading a reception needs the supplier-order dispatch classes, which Dolibarr's reception class does not include on its own (the reception card does). The one-click sheet page now loads them. Generating from the Documents block was not affected.

## [2.15.2] - 2026-09-13

### Fixed
- **Pick sheet in the Create dropdown now looks like the other entries** (bold, uppercase, no caret). It used the generic dropdown-item style instead of the action-button style the order card uses for its Create entries.

## [2.15.1] - 2026-09-13

### Changed
- **Pick sheet moved into the Create dropdown on sales orders.** It is a plain button when the dropdown is disabled (`MAIN_REMOVE_DROPDOWN_CREATE_BUTTONS_ON_ORDER`) or Create has a single entry. Shipment and reception cards keep the button, since they have no Create dropdown.

### Fixed
- **Disabling and re-enabling Binloc no longer switches the pick / place sheets off.** The module remembers which sheets were on (`BINLOC_SHEETS_ACTIVE`) and restores them when it is enabled again.
- **The sheet buttons appear after a file-only upgrade** without a disable/re-enable: opening binloc setup registers any card hooks the module needs but Dolibarr has not recorded yet.

## [2.15.0] - 2026-09-13

### Added
- **Pick and place sheets** — PDF document models in the Documents block of object cards:
  - **Pick sheet on sales orders** (`binlocpick`): bins to take the not-yet-shipped quantity from. Lots are allocated earliest eat-by first and limited to the order's warehouse when set; shortfalls and extra locations are noted.
  - **Pick sheet on shipments** (`binlocpickship`): the exact warehouses and lots the shipment names. When stock has already left (validated with stock decrement on shipment), a banner warns that lot bins may have been cleared, and the product's other bins in that warehouse are shown as *suggested*.
  - **Place sheet on receptions** (`binlocplace`): assigned bin, *suggested* bin (for example a new serial whose product already has a bin in that warehouse), or a write-in box.
  - Rows follow walk order: warehouse prefix, then each level by value position. Bin codes match the labels exactly.
- **Pick sheet / Place sheet buttons** on order, shipment and reception cards when that sheet is switched on. One click generates the sheet through the same document model and opens it in a new tab; the file is also kept in the Documents block, which still works as before. One click generates the sheet through the same document model and opens it in a new tab; the file is also kept in the Documents block, which still works as before.
- **Setup → Pick / place sheets**: a switch per launch point (orders, shipments, receptions), so each organisation enables the one that matches how it picks, or several. The switch is Dolibarr's own document-model activation, so it stays in sync with the native Orders/Shipments/Receptions setup pages. **One section per warehouse** switch: off = one list in walking order across nearby warehouses; on = each warehouse on its own page.

### Notes
- Sheets are saved as `REF-picksheet.pdf` / `REF-placesheet.pdf` and never become the object's main document. After generating, the card's selected model goes back to the site default, so validating an order still regenerates the order PDF.
- **Upgrading from 2.14 by replacing files:** disable and re-enable Binloc once, so Dolibarr registers the new card hooks the buttons need (then switch the sheets on in binloc setup).
- Disabling the module unregisters the sheet models. Re-enable them from binloc setup after re-enabling the module.

## [2.14.0] - 2026-09-13

### Added
- **Warehouse bin code prefix.** A new **Bin code prefix** field on the warehouse card (a Dolibarr extrafield on warehouses, `binloc_code`, also listed under Stock extrafields) is baked into every bin code on labels: with prefix `A`, bin `L21A4` prints as `AL21A4`, and the same bin in sea can `B` prints as `BL21A4` — identical layouts in different warehouses never share a code. Per-layout toggle "Warehouse prefix" (on by default; no effect until a prefix is set). The labels page warns when the prefix is unset or shared by another warehouse. The field is created on module enable and by migration step 2.14.0-1 (run from the setup banner after a file-only upgrade).

## [2.13.1] - 2026-09-13

### Changed
- **Label corner tag no longer pushes the contents down.** The tag now floats top-right; the title, description and contents wrap beside it and take the full width below it, so a large reading-distance tag stops leaving a blank strip under the title. Sub-bin rules stop at the tag instead of running under it.

## [2.13.0] - 2026-09-13

### Added
- **Hover help** on the new features: Dolibarr "?" tooltips on every field of the Bin Labels toolbar and layout panel (what each level filter does, how Output modes differ, what the corner tag and descriptions are, what each Save button stores), on the Warehouse Levels editor (level types including Letter, Generate letters, value codes, Disable/Enable/Delete semantics), and on the bulk-assign quick-assign, batch and Save All actions.
- **Documentation** — README and the in-app user guide rewritten for 2.4–2.13: letter levels and generation, value descriptions and the Key, quick-assign for stockless products, reload-free value entry, CSV letter shorthand, and a full Bin Labels chapter (levels, label anatomy, empty bins, per-level layouts, every layout setting, sheet vs label-printer output with Dymo guidance). The developer-facing extension notes are linked from the README.

## [2.12.0] - 2026-09-12

### Added
- **Label-printer output** — a per-layout Output setting: *Sheet* (the existing edge-to-edge grid for cutting apart) or *Label printer* (one label per page, each page exactly the layout's Width × Height with zero margin — what a Dymo LabelWriter 450 or similar roll printer expects). Because it lives in the layout, bag tags can go to the roll printer while shelf labels stay on sheets. The page shows the page size and print-dialog guidance in roll mode, and warns when no height is set.

### Fixed
- **The Key legend no longer prints.** It shifted the first row on sheets (breaking single-slice cutting) and would burn a label on a roll printer. It stays on the screen preview.
- The print sheet had an 8 mm-ish top offset from the preview grid's margin; removed.

## [2.11.1] - 2026-09-12

### Fixed
- **Bin Labels page spacing and texture** after the 2.11.0 restructure: the layout sections had no inner padding (the theme forces a fieldset border, and the reset had removed the padding instead of the border), the collapsible header had lost its disclosure marker and showed a raw focus outline, the footer rule only underlined its own buttons, and the Print button sat at the far edge of the viewport away from its count. Sections, toolbar, legend and footer now have consistent padding and rhythm; the header has a marker and a proper focus style; primary actions (Refresh, Save for level) use the theme's own action-button colours; Print sits beside its count.

## [2.11.0] - 2026-09-12

### Changed
- **Bin Labels page redesign** — no functional change, same parameters and forms. Controls are grouped into one toolbar of captioned fields (warehouse, level, product search, per-level filters, empty bins, refresh) instead of three loose rows; the layout panel is organised into sections (Label, Padding, Type, Sheet, Show) with short captions and hover hints in place of the long paragraph, a state badge in its summary, and a proper footer for its actions; the results line puts the count and the Print button on one bar; the Key legend renders as code chips grouped per level instead of a run-on sentence. Chips also apply to the bulk-assign page and warehouse tab legends.

## [2.10.0] - 2026-09-12

### Added
- **"Show contents" layout toggle** — untick for identity-only labels (title, corner tag, value description, nothing listed). Meant for the upper levels: a rack-end label names the rack; its shelves carry their own labels. Per-level layouts let it be off for Rack and on for Bag in the same warehouse.

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
