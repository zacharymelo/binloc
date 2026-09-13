# Binloc

Bin-location tracking for Dolibarr. Track where products live inside warehouses using configurable level hierarchies (Row/Bay/Shelf/Bin, Side/Depth/Level/Row/Bin/Bag, Case/Drawer/Bin — whatever matches the building), and print the labels that make the scheme real on the shelving.

## What it does

Each warehouse defines its own location hierarchy — there is no global scheme. Products can have different coordinates in each warehouse they occupy. Binloc records **where stock is**; it deliberately does not decide where stock *should* go (see *Extending* below).

### Recording locations

- **Product card → Bin Locations tab** — everywhere this product lives, across warehouses; assign, edit and remove inline.
- **Warehouse card → Bin Locations tab** — every located product in the warehouse, with **explore-by-bin filters** (any level value, e.g. everything in Row R1 / Shelf A), inline edit and delete, and CSV export.
- **Bulk Bin Assignment** (left menu, under Stock) — one editable table for a whole warehouse with fill-down, batch-set with confirmation, and explicit clearing. A **quick-assign picker** at the top slots *any* product into a bin — including one with no stock in that warehouse yet — so goods can be assigned a home before they arrive.
- **Reception → Bin Placement** and **Manufacturing Order → Bin Locations** tabs — put received goods and produced serials away line by line.
- **Lot/serial card** — a serial's single location, editable inline; a serial is in exactly one place.

All editing is AJAX with CSRF protection; blank inputs never overwrite stored values; clearing is always an explicit action.

### Levels and values

A level is **Text**, **Number**, **Dropdown** (a managed list of values), or **Letter** — a dropdown whose values are letter codes (`A–Z`, continuing into `AA–ZZ`) generated in one step rather than typed. Dropdown and Letter values are referenced by ID, so **renaming a value updates every assignment instantly**; values in use can be disabled but never deleted, so no bin data is silently orphaned.

Every value can carry a **description**: the value stays short because it builds the bin name (`L`), the description keeps the meaning (`Left Rack`). Descriptions appear as tooltips, in a **Key** legend on the bulk, warehouse and label pages, and on printed labels. Bin names like `AL253B3` stay compact without losing what each character means.

The Warehouse Levels editor is a single form with one universal **Save**: level rows, every value and description, and any number of new values (add rows without a page reload; Enter starts the next one) persist together, and the per-value Disable/Delete actions save pending edits first.

### Bin labels

**Bin Labels** (left menu) prints one label per bin at any level of the hierarchy — a tag per shelf, per bag, per rack end. Each label shows its **full location code** (identical to the system's code, the error-prevention rule), its **own level value** as a corner tag sized for reading distance, the value's description, and what is assigned below it grouped by the next level down. A **Bin code prefix** set on each warehouse card (a field the module adds to warehouses) is baked into every code, so identical layouts in different warehouses — two sea cans each with a left and right rack — print `AL21A4` and `BL21A4`, never the same code twice. Contents can be switched off for identity-only labels (rack ends). **Empty bins** — configured value combinations that hold nothing yet — can be included so shelving is labelled before stock arrives; nothing is stored, the enumeration is transient.

Layouts (size, padding, fonts, border, separator, what to show) are per warehouse with an optional override per level, and each layout chooses its **Output**: *Sheet* prints the labels edge to edge with zero gap for single-slice cutting, *Label printer* prints one label per page sized exactly to the stock, verified on a Dymo LabelWriter 450.

### Pick and place sheets

**Create → Pick sheet** on sales orders, and a **Pick sheet** / **Place sheet** button on shipment and reception cards (also available in each card's **Documents** block), generates a printable PDF listing which bins to pick from or put away to, sorted in walking order, with a tick box per line. Bin codes match the labels exactly.
- **Sales order** pick sheet: for teams that pick before a shipment exists. Covers the unshipped quantity, earliest eat-by lots first.
- **Shipment** pick sheet: the exact warehouses and lots on the shipment.
- **Reception** place sheet: the assigned bin, a *suggested* bin, or a write-in box.

Each launch point has its own switch in binloc setup, so every organisation turns on the one that matches how it works. A second switch keeps all warehouses in one walking list or starts each warehouse on its own page. Sheets are saved beside the commercial PDF and never replace it.

### Spreadsheet workflow

**CSV import/export** (Import/Export admin tab): design the bin layout in Google Sheets and import it, and bulk-load or round-trip product→bin assignments per warehouse. Imports preview every planned change before writing and apply all-or-nothing. A `letter` level's whole range is written as a single end code (`Z`, or `AC` to go past Z), so a 700-value level is one cell.

📖 **[User guide](module/docs/USER-GUIDE.md)** — full instructions for setup, assigning, exploring by bin, printing labels, and the CSV formats. The guide ships with the module and opens inside Dolibarr via the **?** help icon on every Bin Locations page. Most fields also have a **?** hover tooltip.

## Upgrading

Replace the module files with the new release. Two situations need one extra click:

- **Schema migrations** (2.0.0's normalised model; 2.4.0's value descriptions): if you re-enable the module after replacing the files, the migration runs automatically. If the module stays enabled, open **module setup** — a banner shows the pending migration with a **Run migration** button. Migrations are versioned, resumable and verified before any destructive step; values that no longer match a list are preserved as "(legacy)" entries.
- **New menu entries** (2.4.0 added Bin Labels): disable and re-enable the module once so Dolibarr registers them.

Everything else — layouts, output modes, tooltips — is a plain file swap.

## Requirements

- Dolibarr 22 or later
- Stock module enabled

## Install

1. Download the latest release zip from the [Releases](https://github.com/zacharymelo/binloc/releases) page (or clone this repo into `htdocs/custom/binloc`).
2. In Dolibarr, go to **Home → Setup → Modules/Applications** and enable **Bin Locations**.
3. Define each warehouse's levels under **Warehouse Levels** (left menu or module setup), then assign, then print labels.

## Development

```bash
docker compose up -d
# Dolibarr at http://localhost:8080
# Login: admin / admin
```

The module directory is mounted at `/var/www/html/custom/binloc` inside the container. Pre-commit runs phpcs with the Dolibarr ruleset; the project's own conventions (SQL built with `$sql .=` lines, AJAX mutations POST-only with a CSRF token, list values resolved to option IDs through `BinlocProductLocation::setRawValue()`) are documented in the code where they apply.

## Extending

Binloc records where stock *is*. Deciding where it *should* go — a bin registry with capacity and status, directed putaway on receptions, "find me an empty bin" — is a WMS concern and is planned as a **separate extending module** (`depends = modBinloc`) rather than growth of the core, so the tool the floor relies on stays a small, correct model of reality.

- [`docs/WMS-EXTENSION.md`](docs/WMS-EXTENSION.md) — the contract: `lib/binloc_bins.lib.php` is the stable seam (bin grouping, enumeration, canonical bin identity), plus the core changes the extension will need.
- Branch `feature/wms-putaway` — the design draft for the extension (`docs/WMS-PUTAWAY.md`).

---

## History

This repo previously hosted a different module called **Wareloc** (a warehouse-nesting tree builder, versions up to 2.1.2) that was abandoned in favour of Binloc, a ground-up rewrite with a different architecture. The repo was renamed from `wareloc` to `binloc` at that point — the old URL redirects. The last Wareloc commit is tagged at `06f5363` if you need to reference it.
