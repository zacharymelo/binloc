# Binloc User Guide

This guide covers everything a warehouse admin or operator does with Binloc day to day. It matches version **2.15.4**. Most fields in the module also carry a **?** hover tooltip with the same information in short form.

## Contents

1. [Concepts](#concepts)
2. [Setting up a warehouse's bin layout](#setting-up-a-warehouses-bin-layout)
3. [Assigning products to bins](#assigning-products-to-bins)
4. [Finding things: explore by bin](#finding-things-explore-by-bin)
5. [Printing bin labels](#printing-bin-labels)
6. [Spreadsheet workflow: CSV import/export](#spreadsheet-workflow-csv-importexport)
7. [Pick and place sheets](#pick-and-place-sheets)
8. [Serialized / lot-tracked products](#serialized--lot-tracked-products)
9. [Settings](#settings)
10. [Permissions](#permissions)
11. [Troubleshooting](#troubleshooting)

---

## Concepts

- **Levels** describe how a warehouse is physically organized, from coarsest to finest — for example *Row → Bay → Shelf → Bin*, or *Side → Depth → Level → Row → Bin → Bag*. Each warehouse defines its own levels (any depth); there is no global scheme.
- Each level has a **type**:
  - **Text** — free entry (e.g. Row "R1", "R2")
  - **Number** — numeric entry only
  - **Dropdown** — pick from a managed list of allowed values (e.g. Shelf "A", "B", "C")
  - **Letter** — a dropdown whose values are letter codes: A to Z, continuing into AA, AB … ZZ. You generate the range in one step instead of typing each value. Otherwise it behaves exactly like a Dropdown.
- A **value** of a Dropdown or Letter level has a short **code** (what appears in the bin name and on inputs — `L`) and an optional **description** (what it means — `Left Rack`). Keep codes short; put the meaning in the description. Descriptions show as tooltips, in a **Key** legend on the bulk, warehouse and label pages, and on printed labels.
- A **bin** is a distinct combination of level values. Bins are not records — a bin exists because something is assigned there, or because its values are configured (which is what lets empty bins be printed). The **bin code** is the values joined in level order: Side `A`, Rack `L`, Slot `2`, Shelf `5`, Row `3`, Column `B`, Bag `3` → `AL253B3` (a separator can be configured for labels).
- A **bin location** (assignment) is one product's coordinates in one warehouse: a value for some or all of that warehouse's levels, plus an optional note. Non-serialized products get one assignment per warehouse; serialized/lot products get one per lot — and a serial can only be in one place at a time.
- Dropdown and Letter values are referenced, not copied: **renaming a value updates every assignment that uses it, instantly**. A value that is in use cannot be deleted — you can *disable* it instead, which hides it from new entry while existing assignments keep displaying it (marked "legacy").

## Setting up a warehouse's bin layout

**Left menu → Warehouse Levels** (also under module setup, or via the *Manage Levels* button on a warehouse's Bin Locations tab).

The whole page is **one form with one Save button**. Level rows, every value and description, and any new values you have queued are saved together — you never lose an edit by clicking something else on the page.

1. Pick the warehouse.
2. Add levels with **Add level**; give each a name and type. Reorder with the ↑/↓ arrows — order is display order (and bin-code order) and never affects stored data.
3. **Save**. A Dropdown or Letter level then shows its **Allowed Values** editor below.
4. In each values editor:
   - Each row has the **code**, its **description**, how many locations use it, and **Disable / Enable / Delete**.
   - **Add value** appends an empty row; pressing **Enter** in a new-value field starts the next one, so a long list is *type-Enter-type-Enter*, then one Save. Nothing is written until you save.
   - **Letter levels** have **Generate letters up to** — enter `Z` for A–Z, or a double letter such as `AC` to continue past Z into AA, AB, AC. Only missing codes are added; existing ones (and their descriptions) are untouched, so you can extend the range later.
   - **Disable** hides a value from new entry without touching existing assignments; **Delete** is only offered while nothing uses the value. Both save your pending edits first.
   - "used by N location(s)" is a link — click it to see exactly which products those are.
5. To reuse a layout, either use **Copy from warehouse** (only offered while the target has no levels) or the CSV layout import (below).

Removing a level that still holds data does not destroy anything: the level is deactivated and its stored values remain visible on existing assignments.

## Assigning products to bins

There are five ways in, all writing the same data:

- **Bulk Bin Assignment** (left menu, under Stock) — the main tool. Pick a warehouse; the table lists every product with stock there **or with a bin assignment there**, one editable row each. Type into any row and **Save All**.
  - **Add a product to a bin** (the panel at the top) assigns *any* product — including one with no stock in this warehouse yet — pick it, set the bin, **Assign bin**. It then appears in the table. Use this to give incoming goods a home before they arrive.
  - The **↓** arrow on a row copies its values down into empty cells of the rows below.
  - Tick rows and use the **Set selected products to** panel to set the same values on all of them; it asks for confirmation naming the fields and row count, and only writes the fields you filled.
  - Blank inputs never erase stored values on save; clearing a row is an explicit action (trash icon on the row).
  - The list paginates; the rows-per-page selector and page links keep your search.
- **Product card → Bin Locations tab** — everything about one product: warehouses with stock but no bin yet (with an *Assign Location* button), current assignments (edit/remove inline), and *Add to Other Warehouse*.
- **Warehouse card → Bin Locations tab** — every located product in one warehouse, with inline edit (pencil) and remove (trash) per row.
- **Reception card → Bin Placement tab** — put received goods away line by line; the destination warehouse per line is pre-selected and changing it swaps the bin fields without losing what you typed.
- **Manufacturing Order card → Bin Locations tab** — assign bins to serials produced by the MO (rows appear once the lot records exist).

## Finding things: explore by bin

On a warehouse's **Bin Locations** tab, the search bar has one filter per level next to the product search:

- Dropdown and Letter levels filter by exact value (disabled/legacy values are listed too, so strays are findable). Hover a value to see its description.
- Text/number levels filter by partial match ("R" matches R1 and R2).

Filters combine — Row = R1 **and** Shelf = A shows exactly what's in that bin. They survive sorting and pagination, and they live in the URL, so a filtered view can be bookmarked or shared. The **Key** line above the table explains every code that has a description.

From the admin side, every "used by N location(s)" link on the Warehouse Levels page opens this view pre-filtered to that value.

## Printing bin labels

**Left menu → Bin Labels.** Prints one label per bin at whichever level of the hierarchy you choose — a tag per shelf, per bag, per rack end.

### What a label shows

- **Title** — the bin's full code (`AL253B`), identical to the code the system uses. Pickers match the label against their pick list, so the two must never differ. When the warehouse has a **Bin code prefix** (see below), it is baked in front: `A` + `L253B` = `AL253B`.
- **Warehouse prefix** — set on the warehouse card (**Stock → warehouse → Bin code prefix**, a field the module adds; it is also listed under Stock extrafields). One or two letters, unique per warehouse. This is what keeps identical layouts apart: two sea cans that both have a left rack, row 2, bin A4 print `AL2A4` and `BL2A4`, never the same code twice. The labels page warns if a warehouse has no prefix or shares one.
- **Corner tag** — the label's own level value (`B` on a shelf label, `3` on a bag label), top right, in its own font size. It is what you look for from a distance; the title is what you confirm up close. Rule of thumb from signage practice: character height in cm ≈ reading distance in cm ÷ 200, so a shelf number read from 2 m wants ~1 cm ≈ 28 pt.
- **Description** — the own value's description (`Blue Rack`) under the title.
- **Contents** — everything assigned below the bin, grouped by the next level down (`Bag 3`, with each bag's products), with any deeper sub-path shown compactly on each item (`B3 · PART-1`). Product names and lot numbers are optional.

### Choosing what to print

1. Pick the **warehouse**.
2. **Print labels for** — the level the labels are stuck on. *Shelf* gives one label per shelf listing its bags; *Bag* gives one per bag. The label's identity is the path from the top level down to this one.
3. Narrow the selection:
   - **Product** search — only bins holding matching products.
   - **Level filters** — only bins under those values (e.g. Side L, Depth 2).
   - **Include empty bins** — also print a label for every configured combination of values down to the chosen level, so shelving can be labelled before stock arrives. Nothing is stored; this only affects what prints. Only Dropdown/Letter levels can be enumerated — if a Text or Number level sits above the chosen level, fix it with its filter (e.g. Row = R5) and the levels below it enumerate. The page says so when a level blocks enumeration, and stops at 1000 labels.
4. Check the preview, then **Print labels**. It opens a print-only page and the print dialog. The **Key** legend is shown on screen only — it is never printed, because on a sheet it would shift the first row and on a label printer it would burn a label.

### Layouts: size, fonts, and what to show

Open **Label layout — ‹level›** above the preview (admin right). Layouts are **per warehouse**, with an optional **override per level** — a bag tag and a shelf tag differ in stock and in reading distance. The badge in the header says whether the level has its own layout or is using the warehouse default.

| Setting | Meaning |
|---|---|
| Width / Height (mm) | Label size as you read it. Height 0 = automatic (sheet output only). |
| Padding top / right / bottom / left (mm) | Space between the label edge and its content. A large top padding leaves a blank strip at the top of every label — e.g. 21 mm for slide-in bin holders — without any hardcoded rectangle. |
| Body (pt) | Base font size for the contents; the title is 1.7× this. |
| Corner tag (pt) | Font size of the corner tag; 0 hides it. Size it for reading distance. |
| Output | **Sheet**: labels flow edge to edge with zero gap on the paper you pick, so a sheet is cut with a single slice between labels; rows stretch to equal height. **Label printer**: one label per page, each page exactly Width × Height with no margin (see below). |
| Border (mm) | 0 = none (pre-cut sticker stock). |
| Margin (mm) | Blank margin around the grid on a sheet; ignored for label-printer output. |
| Separator | Text between code parts: blank gives `R5B`, `-` gives `R5-B`. |
| Warehouse prefix | Bakes the warehouse's Bin code prefix into every code on the label (on by default; does nothing until the prefix is set on the warehouse card). |
| Show contents | Untick for identity-only labels — title, corner tag, description, nothing listed. Meant for the upper levels: a rack-end label names the rack; its shelves carry their own labels. |
| Show value description / product names / lot-serial | What appears on the label. |

Three buttons: **Save for ‹level›** stores an override for this level only; **Save as warehouse default** stores the fallback every level without its own layout uses; **Use warehouse default** removes this level's override.

### Label printers (Dymo LabelWriter and similar)

Roll printers advance one die-cut label per page, so set the level's layout to **Output: Label printer**, enter **Width and Height as the label stock** (Dymo 30252 address 89 × 28, 30256 shipping 102 × 59, 30334 multipurpose 57 × 32 mm) and usually **Border 0**. The print page then declares each label as its own page of exactly that size. In the print dialog choose the Dymo and the matching label size (the browser normally preselects it) and print at full scale, not fit-to-page. The printer itself has no notion of orientation — the driver rotates the page as needed — so enter the size as you read the label; the side that crosses the print head must be at most 56 mm on a LabelWriter. Verified on a LabelWriter 450.

Because output is a layout setting and layouts are per level, bag tags can go to the roll printer while shelf labels stay on sheets.

## Spreadsheet workflow: CSV import/export

**Home → Setup → Modules → Bin Locations → Import/Export.** Built for Google Sheets/Excel: export, edit in the sheet, **File → Download → CSV**, import. Export output is directly re-importable.

Every import is a two-step flow: **Upload and preview** shows each planned change and every error (with line numbers) before anything is written; **Confirm import** applies all-or-nothing — a file with any invalid row imports nothing rather than half-importing.

### Layout CSV — the bin structure itself

```csv
warehouse;level;label;type;allowed_values
WH-A;1;Row;text;
WH-A;2;Bay;number;
WH-A;3;Shelf;list;A|B|C
WH-B;1;Side;letter;C
WH-B;2;Bin;list;X|Y|Z
```

- One row per level. `warehouse` is the warehouse ref; `level` is the position (1 = coarsest); `type` is `text`, `number`, `list` or `letter`; `allowed_values` is pipe-separated and only used for `list` and `letter`.
- **Letter shorthand**: for a `letter` level, a single end code in `allowed_values` means the whole range — `Z` generates A–Z, `AC` continues past Z into AA, AB, AC. A pipe-separated list (`A|C|E`) is still taken literally. Export writes a clean, gapless range back as the shorthand, so exported files stay readable.
- Import matches existing levels **by label** (case-insensitive): matching levels are updated in place, new labels create new levels, and missing allowed values are added.
- Import is **additive** — it never deletes levels or values. Levels in the database but not in the file are kept (and reported as such in the preview).
- Value descriptions are not part of the layout CSV; set them on the Warehouse Levels page.
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
- A dropdown or letter value not in the allowed list makes the row an error — unless you tick **Create missing dropdown values**, in which case the preview shows each value that will be added.
- Unknown product refs or lot numbers are always errors (the import never creates products or lots).

There's also an **Export assignments (CSV)** button directly on each warehouse's Bin Locations tab.

## Pick and place sheets

A sheet is a printable PDF listing where to pick goods from or put them away, one line per bin, with a tick box. There are two ways to make one:

- **The shortcut:** on a sales order, **Create → Pick sheet**. On a shipment or reception, the **Pick sheet** / **Place sheet** button among the action buttons at the bottom of the card. One click generates the sheet and opens it in a new tab, ready to print.
- **The Documents block** at the bottom left of the card: choose **Pick sheet** / **Place sheet** in the model list and click **Generate**. Use this to regenerate or to email the sheet.

Either way the file is kept in the Documents block. The shortcut only appears when that sheet is switched on (below). After upgrading Binloc by replacing its files, open the Binloc **Settings** page once so the shortcuts are registered. Disabling and re-enabling the module keeps the sheet switches as they were.

### Turning sheets on

**Home → Setup → Modules → Bin Locations → Settings → Pick / place sheets.** Each launch point has its own switch, so turn on what matches how your team works:

| Switch | Use it when |
|---|---|
| **Pick sheet on sales orders** | Pickers work from the order and the shipment is created afterwards from what they pulled. |
| **Pick sheet on shipments** | The shipment is created first and names the warehouses and lots to pick. |
| **Place sheet on receptions** | Received goods need putting away. |

The switches are the same ones as the Status column of the PDF models on the native Orders, Shipments and Receptions setup pages, so either page can be used.

**One section per warehouse**:
- **Off:** everything is one list in walking order, grouped by bin code prefix. Use this for warehouses close together, such as sea cans in one yard.
- **On:** each warehouse starts on its own page. Use this for sites across town.

### What each sheet lists

- **Sales order:** only the quantity not yet shipped (validated shipments count; drafts do not). An order that is already partly shipped lists only the remaining lines.
  - Lot/serial products are taken from lots with stock, earliest eat-by date first. Other products are taken from warehouses with stock.
  - If the order has a warehouse set, only that warehouse is used.
  - Notes say how much stock is in each spot, how many other locations exist, and when stock is short.
- **Shipment:** exactly the warehouses and lots on the shipment.
- **Reception:** where each received line goes. Lines without a bin get an empty **Put in bin** box to write the bin in; assign it afterwards on the reception's **Bin Placement** tab.

The **Bin** column only shows a bin in the line's own warehouse: the warehouse picked from, or the reception line's target warehouse. A bin belongs to one warehouse, so printing another warehouse's bin would send goods to a shelf the stock isn't booked into.

Each line's bin status:
- **Bin shown, no note:** the product (or that exact lot) is assigned there.
- **Suggested:** there is no assignment for this exact lot, but the product has a bin in that warehouse. This is typical for a brand-new serial on a reception.
- **No bin assigned:** nothing is recorded for the product in that warehouse. If the product has bins in other warehouses, the note adds **Has a bin in: …** with those warehouses. On a reception, that usually means either the target warehouse should be changed to one of those (before validating), or the product needs a bin in this warehouse (assign it on the **Bin Placement** tab).

Lines are sorted in walking order: warehouse prefix, then each level in order, using the order of dropdown and letter values on the Warehouse Levels page. Lines without a bin come last.

### Good to know

- **Print shipment pick sheets before validating.** If stock is decremented when a shipment is validated, a serial's bin is cleared as it leaves (see below). A sheet generated after that shows a warning banner and falls back to suggested bins.
- Sheets are saved as `REF-picksheet.pdf` / `REF-placesheet.pdf` next to the normal PDF. They never become the object's main document or the default email attachment. After generating one, the card's model list goes back to the site default.
- Kits (sub-products), picking several orders on one sheet, and service lines are not included.

## Serialized / lot-tracked products

- One serial/lot has **one** location anywhere — assigning it in a new warehouse moves it, never duplicates it.
- The lot/serial card shows a **Bin Location** field with inline edit; the Reception and MO tabs handle lots per line.
- A stock-out movement of a serial automatically clears its bin (it physically left). Deleting a lot removes its bin record.

## Settings

**Home → Setup → Modules → Bin Locations → Settings**

- **Auto-clear location when stock drops to zero** — when a (non-serialized) product's stock in a warehouse reaches zero, its bin assignment there is removed automatically. Lot assignments are not touched by this. Note that a product assigned before any stock arrived is only affected once stock has come *and gone*; a pre-assignment with no movements stays.
- **Pick / place sheets** — see [Pick and place sheets](#pick-and-place-sheets).
- **Debug Mode** — enables the read-only diagnostics endpoint at `/custom/binloc/ajax/debug.php` (admins only): table counts, migration status, integrity checks, level configs.

The Settings page also shows the **database migration status banner** (see Troubleshooting).

## Permissions

| Right | Allows |
|---|---|
| *Read bin locations* | viewing all tabs, the Bin Labels page and printing, exporting CSVs |
| *Create/modify bin locations* | assigning/editing/removing bins, bulk assign and quick-assign, importing assignment CSVs |
| *Configure warehouse levels* | the Warehouse Levels editor, value descriptions, layout CSV imports, and label layout settings |

## Troubleshooting

- **"A database migration is pending" banner** (Settings page): appears after upgrading the module files while the module stayed enabled (2.0.0 restructured storage; 2.4.0 added value descriptions). Click **Run migration**. Your data is converted in place; a destructive step only runs after a verification pass. On failure, the banner shows the failing step and a Retry button, and your existing data is untouched.
- **Bin Labels is missing from the menu after an upgrade** — disable and re-enable the module once so Dolibarr registers the new menu entry.
- **A value shows "(legacy)" in a dropdown** — the stored value was disabled or is no longer in the allowed list. It is never blanked automatically; either re-enable the value on the Warehouse Levels page, or pick a current value and save.
- **"Empty bins were not added: ‹level› is a free-text/number level…"** on Bin Labels — a Text or Number level above the chosen label level cannot be enumerated. Set a value in that level's filter (e.g. Row = R5) and the levels below it enumerate.
- **"Label printer mode needs a label height"** — set Height in the level's layout to the label stock; automatic height only works for sheets.
- **Labels come out scaled or clipped on a label printer** — the print dialog picked the wrong stock or fit-to-page: choose the label size matching the layout's Width × Height and print at full scale.
- **"Level N (legacy)" appears in a warehouse's levels** — the 1.x→2.x migration found stored bin data whose level configuration had been deleted. The data was preserved under this placeholder level; rename or fold it as you see fit.
- **CSV import rejects the whole file** — that's by design: fix the listed lines and re-upload. Files with errors are never partially imported.
