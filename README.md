# Binloc

Bin-location tracking for Dolibarr. Track where products live inside warehouses using configurable level hierarchies (Row/Bay/Shelf/Bin, Case/Drawer/Bin, etc.).

## What it does

Each warehouse can define its own location hierarchy — you're not forced into a single global scheme. Products can have different location coordinates in each warehouse they occupy. The module adds tabs to:

- **Product card** — see everywhere this product lives, across warehouses
- **Warehouse card** — see every product assigned to locations in this warehouse
- **Manufacturing Order card** — see locations for MO input/output components
- **Reception card** — see locations for received items

A bulk-assign page lets you set locations across many products at once.

## Requirements

- Dolibarr 22 or later
- Stock module enabled

## Install

1. Download the latest release zip from the [Releases](https://github.com/zacharymelo/binloc/releases) page (or clone this repo into `htdocs/custom/binloc`).
2. In Dolibarr, go to **Home → Setup → Modules/Applications** and enable **Bin Locations**.
3. Configure level names under the module setup page.

## Development

```bash
docker compose up -d
# Dolibarr at http://localhost:8080
# Login: admin / admin
```

The module directory is mounted at `/var/www/html/custom/binloc` inside the container.

---

## History

This repo previously hosted a different module called **Wareloc** (a warehouse-nesting tree builder, versions up to 2.1.2) that was abandoned in favour of Binloc, a ground-up rewrite with a different architecture. The repo was renamed from `wareloc` to `binloc` at that point — the old URL redirects. The last Wareloc commit is tagged at `06f5363` if you need to reference it.
