# Changelog

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
