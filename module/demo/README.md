# Binloc demo data

A small hardware store in two warehouses, for trying Binloc on a test
Dolibarr. Every ref starts with `DEMO-`. Import in numbered order: files 1–3
with Dolibarr's own import tool (Tools › Import), files 4–6 on Binloc's
Import/Export page.

1. `1-warehouses.csv` — Dolibarr dataset *Warehouses*
2. `2-products.csv` — Dolibarr dataset *Products*
3. `3-stock.csv` — Dolibarr dataset *Stocks*
4. `4-binloc-layout.csv` — Binloc *Warehouse layout*
5. `5-binloc-assignments-DEMO-MAIN.csv` — Binloc *Bin assignments*, warehouse DEMO-MAIN
6. `6-binloc-assignments-DEMO-OVFL.csv` — Binloc *Bin assignments*, warehouse DEMO-OVFL

In Dolibarr's import, map each column to its field (e.g. `ref` → *Short name
of location* for warehouses) — the user guide lists every mapping.

The full walkthrough, including what to look at afterwards, is the
"Trying Binloc with demo data" section of the user guide
(`docs/USER-GUIDE.md`, also shown in Dolibarr under Binloc's help page).
