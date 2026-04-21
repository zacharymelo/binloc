# Changelog

## [2.1.3] - 2026-04-21

### Fixed
- Remove dead/duplicate lang keys that collided with Dolibarr core: `Actions`, `Select`, `Save`, `Add`, `Cancel`, `Closed`, `Stock`, `Depth`. All callers were using the generic keys expecting core's values — `Actions` was the notable override (ours was "Actions", core's is "Events") that only survived because we had no callers for it.

## [2.1.2] - 2026-04-03

### Fixed
- Fix phpcs violations — docblocks, string concats, underscore-prefixed function renames

## [2.1.1] - 2026-03-28

### Fixed
- Pre-compute AddLabel JS variable to prevent broken script block

## [2.1.0] - 2026-03-28

### Added
- Per-node bulk-add children
- Proper ORM for deactivate and rename

## [2.0.2] - 2026-03-28

### Fixed
- New depth row inserted outside table — append to table body instead

## [2.0.1] - 2026-03-28

### Fixed
- PHP parse error in admin/setup.php JS block — broken string context around dol_escape_js() call

## [2.0.0] - 2026-03-28

### Added
- Native warehouse hierarchy tree builder (complete rewrite)

## [1.2.0] - 2026-03-28

### Added
- Per-warehouse hierarchy overrides
- AJAX level fields
- Admin UX improvements

## [1.1.0] - 2026-03-28

### Added
- Initial wareloc module — sub-warehouse product location tracking
