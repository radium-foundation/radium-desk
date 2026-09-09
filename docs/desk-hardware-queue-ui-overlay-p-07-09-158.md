# Overlay Hardware Queue UI polish — RadiumDesk-P-07-09-158

**Date:** 2026-09-09  
**Source UI SHA:** `367535cdee945e36bbe72540e1393691447b2cc5`  
**Mechanism:** individual `install -m 644` of three application files from `git archive 367535cd` plus Vite `manifest.json` and `assets/app-p7q6pM7z.css`. Not `./tools/desk deploy`. Not full-tree rsync. No `--delete`. No migrate. No `.env`.

**Excluded:** `03ee5070` Order.php / Eligibility; ledger; tests; JS chunks.

## Backup

`/var/www/radium-desk/storage/app/private/overlays/p-07-09-158-20260909T153855Z`

## Overlay set

- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php`
- `resources/views/dashboard/partials/hardware-product-cell.blade.php`
- `resources/views/dashboard/partials/hardware-workspace.blade.php`
- `public/build/manifest.json`
- `public/build/assets/app-p7q6pM7z.css` (new)

Previous `public/build/assets/app-CNsC_50T.css` was kept. Dashboard JS remained `dashboard-CovULnXg.js`.

## Production checks

- Order.php and HardwareFulfilmentEligibility.php SHA256 unchanged vs backup.
- `/up` 200; login 200 with new CSS; dashboard URLs 302 to login (auth required).
- Empty hardware workspace view renders Date/Time column.
- Web root stayed `755 ravi:ravi`.
