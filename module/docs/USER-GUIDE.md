# Binloc User Guide

This guide covers everything a warehouse admin or operator does with Binloc day to day. It matches version **2.3.0**.

## Contents

1. [Concepts](#concepts)
2. [Setting up a warehouse's bin layout](#setting-up-a-warehouses-bin-layout)
3. [Assigning products to bins](#assigning-products-to-bins)
4. [Finding things: explore by bin](#finding-things-explore-by-bin)
5. [Spreadsheet workflow: CSV import/export](#spreadsheet-workflow-csv-importexport)
6. [Serialized / lot-tracked products](#serialized--lot-tracked-products)
7. [Settings](#settings)
8. [Permissions](#permissions)
9. [Troubleshooting](#troubleshooting)

---

## Concepts

- **Levels** describe how a warehouse is physically organized, from coarsest to finest — for example *Row → Bay → Shelf → Bin*, or *Case → Drawer*. Each warehouse defines its own levels (up to any depth); there is no global scheme.
- Each level has a **type**:
  - **Text** — free entry (e.g. Row "R1", "R2")
  - **Number** — numeric entry only
  - **Dropdown** — pick from a managed list of allowed values (e.g. Shelf "A", "B", "C")
- A **bin location** (assignment) is one product's coordinates in one warehouse: a value for some or all of that warehouse's levels, plus an optional note. Non-serialized products get one assignment per warehouse; serialized/lot products get one per lot — and a serial can only be in one place at a time.
- Dropdown values are referenced, not copied: **renaming a value updates every assignment that uses it, instantly**. A value that is in use cannot be deleted — you can *disable* it instead, which hides it from new entry while existing assignments keep displaying it (marked "legacy").

## Setting up a warehouse's bin layout

**Home → Setup → Modules → Bin Locations → Warehouse Levels** (or the *Manage Levels* button on a warehouse's Bin Locations tab).

1. Pick the warehouse.
2. Add levels with the **Add level** button; give each a name and type. Reorder with the ↑/↓ arrows — order is display order only and never affects stored data.
3. **Save**.
4. For each Dropdown level, an **Allowed Values** editor appears below. Add values there; each value shows how many locations currently use it.
   - **Rename** updates every existing assignment.
   - **Disable** hides a value from new entry without touching existing assignments.
   - **Delete** is only offered while nothing uses the value.
   - "used by N location(s)" is a link — click it to see exactly which products those are.
5. To reuse a layout, either use **Copy from warehouse** (only offered while the target has no levels) or the CSV layout import (below).

Removing a level that still holds data does not destroy anything: the level is deactivated and its stored values remain visible on existing assignments.

## Assigning products to bins

There are five ways in, all writing the same data:

- **Product card → Bin Locations tab** — everything about one product: warehouses with stock but no bin yet (with an *Assign Location* button), current assignments (edit/remove inline), and *Add to Other Warehouse*.
- **Warehouse card → Bin Locations tab** — every located product in one warehouse, with inline edit (pencil) and remove (trash) per row.
- **Bulk Bin Assignment** (left menu, under Stock) — every product with stock in a warehouse in one editable table. Type into any row and *Save All*. Extras:
  - The **↓** arrow on a row copies its values down into empty cells of the rows below.
  - Tick rows and use the **batch panel** to set the same values on all of them; it asks for confirmation naming the fields and row count, and only writes the fields you filled.
  - Blank inputs never erase stored values on save; clearing a row is an explicit action (trash icon on the row).
- **Reception card → Bin Placement tab** — put received goods away line by line; the destination warehouse per line is pre-selected and changing it swaps the bin fields without losing what you typed.
- **Manufacturing Order card → Bin Locations tab** — assign bins to serials produced by the MO (rows appear once the lot records exist).

## Finding things: explore by bin

On a warehouse's **Bin Locations** tab, the search bar has one filter per level next to the product search:

- Dropdown levels filter by exact value (disabled/legacy values are listed too, so strays are findable).
- Text/number levels filter by partial match ("R" matches R1 and R2).

Filters combine — Row = R1 **and** Shelf = A shows exactly what's in that bin. They survive sorting and pagination, and they live in the URL, so a filtered view can be bookmarked or shared.

From the admin side, every "used by N location(s)" link on the Warehouse Levels page opens this view pre-filtered to that value.

## Spreadsheet workflow: CSV import/export

**Home → Setup → Modules → Bin Locations → Import/Export.** Built for Google Sheets/Excel: export, edit in the sheet, **File → Download → CSV**, import. Export output is directly re-importable.

Every import is a two-step flow: **Upload and preview** shows each planned change and every error (with line numbers) before anything is written; **Confirm import** applies all-or-nothing — a file with any invalid row imports nothing rather than half-importing.

### Layout CSV — the bin structure itself

```csv
warehouse;level;label;type;allowed_values
WH-A;1;Row;text;
WH-A;2;Bay;number;
WH-A;3;Shelf;list;A|B|C
WH-B;1;Aisle;text;
WH-B;2;Bin;list;X|Y|Z
```

- One row per level. `warehouse` is the warehouse ref; `level` is the position (1 = coarsest); `type` is `text`, `number` or `list`; `allowed_values` is pipe-separated and only used for `list`.
- Import matches existing levels **by label** (case-insensitive): matching levels are updated in place, new labels create new levels, and missing allowed values are added.
- Import is **additive** — it never deletes levels or values. Levels in the database but not in the file are kept (and reported as such in the preview).
- Both `,` and `;` delimiters are accepted; UTF-8 (with or without BOM).

### Assignments CSV — products into bins, per warehouse

```csv
product;product_label;lot;Row;Bay;Shelf;note
P1;Product 1;;R1;3;A;fast mover
P4;Serialized product;LOT001;R2;1;B;
```

- Header: `product`, `product_label` (informational, ignored on import), `lot` (batch number, empty for non-serialized), **one column per level label**, `note`.
- Rows are upserts matched on product ref + lot.
- **Level columns present in the file are authoritative**: an empty cell clears that level; a row with all level cells empty removes the assignment entirely. Level columns you leave out of the file are left untouched.
- A dropdown value not in the allowed list makes the row an error — unless you tick **Create missing dropdown values**, in which case the preview shows each value that will be added.
- Unknown product refs or lot numbers are always errors (the import never creates products or lots).

There's also an **Export assignments (CSV)** button directly on each warehouse's Bin Locations tab.

## Serialized / lot-tracked products

- One serial/lot has **one** location anywhere — assigning it in a new warehouse moves it, never duplicates it.
- The lot/serial card shows a **Bin Location** field with inline edit; the Reception and MO tabs handle lots per line.
- A stock-out movement of a serial automatically clears its bin (it physically left). Deleting a lot removes its bin record.

## Settings

**Home → Setup → Modules → Bin Locations → Settings**

- **Auto-clear location when stock drops to zero** — when a (non-serialized) product's stock in a warehouse reaches zero, its bin assignment there is removed automatically. Lot assignments are not touched by this.
- **Debug Mode** — enables the read-only diagnostics endpoint at `/custom/binloc/ajax/debug.php` (admins only): table counts, migration status, integrity checks, level configs.

The Settings page also shows the **database migration status banner** (see Troubleshooting).

## Permissions

| Right | Allows |
|---|---|
| *Read bin locations* | viewing all tabs and exporting CSVs |
| *Create/modify bin locations* | assigning/editing/removing bins, bulk assign, importing assignment CSVs |
| *Configure warehouse levels* | the Warehouse Levels editor and layout CSV imports |

## Troubleshooting

- **"A database migration is pending" banner** (Settings page): appears after upgrading the module files from 1.x while the module stayed enabled. Click **Run migration**. Your data is converted in place; the destructive final step only runs after a verification pass confirms every value was migrated. On failure, the banner shows the failing step and a Retry button, and your legacy data is untouched.
- **A value shows "(legacy)" in a dropdown** — the stored value was disabled or is no longer in the allowed list. It is never blanked automatically; either re-enable the value on the Warehouse Levels page, or pick a current value and save.
- **"Level N (legacy)" appears in a warehouse's levels** — the 1.x→2.x migration found stored bin data whose level configuration had been deleted. The data was preserved under this placeholder level; rename or fold it as you see fit.
- **CSV import rejects the whole file** — that's by design: fix the listed lines and re-upload. Files with errors are never partially imported.
