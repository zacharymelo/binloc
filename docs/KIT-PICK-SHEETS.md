# Kit contents on pick sheets

Status: shipped in 2.22.0.

## Problem

A Dolibarr kit (a product with sub-products, *Virtual products* in the Products module setup) appears on a pick sheet as one line. The picker sees the kit's reference but not what is in it, so cannot pull the parts. Shipment sheets went further and dropped the kit's component lines on purpose.

## What the sheet shows

```
[ ]  AL21A1   BOLT-M8        Hex bolt M8                 12
     ------   KIT-9          Seal kit                     4   Kit: pick the items below
  [ ] AR03B4    SEAL-A       Shaft seal                   4
  [ ] AR03C1    ORING-12     O-ring 12 mm                 8
[ ]  AR05A2   PUMP-7         Transfer pump                1
```

- The kit's own row is a heading: shaded, no tick box, no bin, the number of kits as quantity.
- Its components follow, indented, each with its own tick box, bin and quantity (kits × quantity per kit).
- The first row that is not indented is outside the kit.
- A kit inside a kit is a heading at the component indent, with its own components one step further in.

## Rules

1. **When kits are expanded.** Only when Dolibarr moves component stock for a kit: `PRODUIT_SOUSPRODUITS` on, and neither `INDEPENDANT_SUBPRODUCT_STOCK` nor `PRODUIT_SOUSPRODUITS_ALWAYS_DISABLE_CHILDREN_STOCK_MOVE` set. Otherwise the kit is stocked as a unit and stays one ordinary line.
2. **Sales order and manufacturing order sheets.** The kit tree is read from `llx_product_association`. Each component's quantity is allocated from stock exactly like any other line (pick priority, the document's warehouse, lots, shortfall rows).
3. **Shipment sheets.** Dolibarr 22 and later store a kit's components as shipment sub-lines (`expeditiondet.fk_parent`) with their own warehouse and lots. The sheet lists those, since they are what the shipment will move. A kit line without sub-lines (Dolibarr 21, or a shipment made before the upgrade) falls back to rule 2 in the line's warehouse.
4. **Service components** are skipped. A kit whose components are all services stays one ordinary line.
5. **Walking order.** A kit is kept together: its block sits where its first component falls in the walk, and its components are in walking order inside it. With *One section per warehouse* on, the heading is repeated in every warehouse section that holds one of its components.
6. **Place sheets** (receptions, MO finished goods) are not expanded.

## Where the code lives

- `module/lib/binloc_sheets.lib.php`
  - `binloc_sheet_kits_enabled()`, `binloc_sheet_kit_tree()` — rule 1 and the tree, one query per tree depth.
  - `binloc_sheet_pick_line()` — picks a product or, for a kit, adds the heading and recurses into components.
  - `binloc_sheet_rows_from_shipment()` — reads sub-lines instead of skipping them.
  - `binloc_sheet_arrange_kits()` — rule 5, called from `binloc_sheet_finalize()`.
  - New row fields: `kit_id`, `kit_path`, `kit_header`, `kit_depth`.
- `module/core/modules/binloc/binloc_sheet_pdf.class.php` — heading row and indent.

## Steps

- [x] Plan (this file, README, user guide, changelog)
- [x] Row model and kit tree
- [x] Order and MO sheets
- [x] Shipment sheets
- [x] Renderer: heading row, indent, specimen preview
- [x] Verified on the dev stack (Dolibarr 23): order and shipment sheets with a kit holding a nested kit, combined and one section per warehouse
- [ ] Not yet exercised: a kit as an MO material, and the Dolibarr 21 shipment fallback (kit line without sub-lines)

## Not included

- A setup switch to turn expansion off.
- Picking a pre-assembled kit from its own bin when component stock also moves (`PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE`).
- Kits on place sheets.
