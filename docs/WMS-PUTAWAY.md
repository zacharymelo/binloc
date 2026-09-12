# Directed putaway & bin registry — design draft

Branch: `feature/wms-putaway`. Status: **design only, no code.** This is the
seed for a separate extending module (working name `binwms`, its own repo and
zip, `$this->depends = array('modBinloc')`). It exists here so the design can
be iterated next to the core it extends; it moves out when implementation
starts. Read `docs/WMS-EXTENSION.md` on `main` first — it defines the seam.

## Problem

Binloc records where stock *is*. Two things it cannot do, and should not:

1. **Know a bin exists before something is in it** — needed for "find me an
   empty bin", occupancy reporting, and capacity.
2. **Choose where incoming stock should go** — directed putaway on receptions
   and MO output.

## Why a separate module

- The core's model ("bins exist because stock is there") cannot drift from
  the physical warehouse. A registry can, and needs a lifecycle (retire,
  merge, move, block). That lifecycle should not leak into label printing,
  bulk assignment, or CSV round-trips.
- Slotting policy is opinionated (fixed vs random, affinity, velocity zones).
  Opinions belong in a replaceable layer.
- The extension can ship, break, and iterate independently of the tool the
  floor already relies on.

## Data model (proposal)

`llx_binwms_bin`

| column | notes |
|---|---|
| rowid, entity | |
| fk_entrepot | |
| fk_level | the level this bin is at (a registered shelf vs a registered bag) |
| bin_key | canonical identity: `level:o<option>` / `level:v<text>` parts joined with `\|`, exactly `binloc_bins_from_rows()`'s `key`. **Unique per warehouse.** Never store the display code. |
| code_cache | display code at last sync, for lists only; recomputed on demand |
| status | active / blocked / retired |
| capacity_qty, capacity_unit | optional; null = unlimited |
| function_name | optional, "Quarantine", "Consumables" — function, never contents (see the naming discussion in the 2.9.0 design notes) |
| notes, date_creation, tms, fk_user_* | |

`llx_binwms_rule` (slotting policy per warehouse, v2 — start with hard-coded
defaults)

No foreign key to Binloc's assignment table: reconciliation is by `bin_key`.

## Occupancy

Occupancy is **derived, never stored**: for a registered bin, the assignments
whose values map to a key with that prefix (`binloc_bins_from_rows` over
`BinlocProductLocation::fetchAllByWarehouse`). Storing occupancy would create
the drift the split was meant to avoid. Capacity is the only stored number.

## Registry population

Additive, like every Binloc import:

- **Auto-register on assignment** — a Binloc trigger the module listens to.
  Requires core to fire an event on `createOrUpdate`; add
  `BINLOC_LOCATION_SAVED` / `BINLOC_LOCATION_DELETED` triggers in core when
  this lands (small, core-side change, keep it in the seam doc).
- **CSV** — `warehouse; <one column per level>; status; capacity; function_name`,
  reusing Binloc's level-column conventions and its dry-run/apply pattern.
- **Scoped generator** — cartesian product under a parent prefix via
  `binloc_enumerate_bins($wh_levels, $level, $fixed)`; never unscoped.

## Directed putaway (v1 rules, in order)

1. **Same-product affinity**: a bin already holding this product with
   capacity left.
2. **Fixed home**: a bin whose `function_name`/rule names this product or
   its category.
3. **Nearest empty** under the warehouse's receiving zone (a configured
   prefix), walking the level chain in position order.
4. Otherwise: no suggestion, never a guess — the user picks.

Output: a suggestion per reception/MO line, prefilled into the bulk table
(core's `binloc_render_bulk_table()` rows via `values`), user confirms with
the existing Save All. The module never writes assignments itself.

## Core changes this will need (tracked, not done)

- Trigger events on assignment save/delete (`BinlocProductLocation`).
- A hook context in `binloc_render_bulk_table()` to prefill/annotate rows.
- A hook on `labels.php` card rendering to print `function_name`.

## Open questions

- Is a "retired" bin hidden from labels, or printed with a strike-through
  for physical removal?
- Capacity unit: quantity only, or volume/weight from product dimensions?
- Does putaway need to *reserve* a bin between suggestion and confirmation?
  (Probably not at this scale; note it.)
