# Changelog

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
