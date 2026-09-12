# Extending Binloc toward WMS features

Developer notes. Not shipped in the module zip (the in-app user guide lives in
`module/docs/`).

## What Binloc is, and deliberately isn't

Binloc records **where stock is**: one row per (product, warehouse, lot) with a
value per configured level. It is a *truth-recording* tool. It does not decide
where stock *should* go, it does not know a bin exists until something is put
in it, and it has no notion of capacity, velocity, or zones beyond what the
level hierarchy expresses.

Those are WMS concerns. They are real, they are wanted, and they belong in a
**separate extending module** — its own repo and zip, `depends = modBinloc` —
so the core stays a small, correct model of reality and the WMS layer can be
opinionated (and replaceable) without destabilising it. Work on that module
starts on the `feature/wms-putaway` branch (design draft in
`docs/WMS-PUTAWAY.md` there), then moves to its own repository.

## The seam: `lib/binloc_bins.lib.php`

Everything that needs to reason about **bins** rather than assignments goes
through this file. It is HTML-free and page-state-free on purpose; the labels
page is its first consumer, the WMS module is the intended second.

| Function | Contract |
|---|---|
| `binloc_level_chain($wh_levels)` | Level rowids in position order, top first. |
| `binloc_next_level($wh_levels, $level_id)` | The level below `$level_id`, `0` at the bottom. |
| `binloc_default_label_level($wh_levels, $legacy)` | Second-deepest level (the "parent" of the finest split). |
| `binloc_value_key($entry)` | Identity of one value: option rowid for dropdown/letter levels (survives renames), lowercase text otherwise. **This is the canonical bin identity** — anything that stores a reference to a bin must store this, never the display code. |
| `binloc_bins_from_rows($rows, $wh_levels, $level_id, $sep)` | Group value rows into bins at a level: `{key, code, own_value, own_description, items, subbins, is_empty}`. Rows may be assignments or bare `{values}` rows (enumerated bins). |
| `binloc_enumerate_bins($wh_levels, $level_id, $fixed, $max)` | Cartesian product of configured values down to a level. Only dropdown/letter levels enumerate; free-text levels must be fixed via `$fixed`. Capped. |

Value entries everywhere have the shape `{fk_option, value, display, description}`
— identical to `BinlocProductLocation::$values` — so rows from the database,
rows from CSV, and enumerated rows are interchangeable.

## What the WMS module would add, and why it isn't in core

**Bin registry** (`llx_binwms_bin`, or similar): one row per *declared* bin —
warehouse, canonical key (from `binloc_value_key`, per level), and per-bin
attributes: capacity, status (active / blocked / retired), zone flags, and —
the one attribute users keep asking for — a function name ("Quarantine",
"Consumables"). Kept out of core because a registry is a second model of the
warehouse that drifts from the physical one and needs a lifecycle (retire,
merge, move); core's "bins exist because stock is there" model cannot drift.
Empty-bin *printing* is already solved in core without a registry
(`binloc_enumerate_bins`, transient).

**Directed putaway**: given incoming stock (reception line, MO output),
propose a bin. Inputs the module can already get from core: which bins exist
under a prefix (`binloc_enumerate_bins`), which are occupied and by what
(`binloc_bins_from_rows` over `fetchAllByWarehouse`), the product's current
locations (`fetchAllByProduct`). What it must add itself: capacity and
occupancy accounting, slotting rules (fixed vs random, same-product affinity,
velocity zones), and the UI hook on the reception/MO tabs — core's
`tab_reception_locations.php` / `tab_mo_locations.php` render the shared bulk
table via `binloc_render_bulk_table()`; a `disabled_hint`/prefill on those
rows is the natural insertion point, and a Dolibarr hook context should be
added there when the module lands rather than pre-emptively.

**Occupancy / empty-bin queries** ("find an empty bin under AL25"): registry
minus in-use, or enumeration minus in-use for option-backed hierarchies.

## Rules for the extending module

1. Never write to Binloc's tables directly; use `BinlocProductLocation`
   (`createOrUpdate`, `setRawValue` — list values must resolve to option
   rowids) and read through the bins lib.
2. Reference bins by canonical key, not by display code. Codes change when a
   value is renamed; keys don't.
3. Treat core's assignments as the truth about *where things are*; the
   registry is the truth about *what bins are declared* — and reconcile
   visibly, never silently.
4. Keep label rendering in core. The module may add per-bin data the label
   *shows* (a function name), exposed through a hook, not by forking
   `labels.php`.
