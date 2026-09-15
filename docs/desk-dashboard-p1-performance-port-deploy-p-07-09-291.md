# Port dashboard SSR performance onto current main + production overlay

**Prompt ID:** RadiumDesk-P-07-09-291  
**Date:** 2026-09-15  
**Source:** `04ad362788763d4049a53b4779434a3d8f836ece` on `cursor/dashboard-p1-performance-ade8`  
**Do not deploy that branch.** Cherry-pick onto current `origin/main`.

## Pre-change

| Item | Value |
|------|--------|
| `origin/main` | `c738d6355bfd6793e663ef0b3cdea93dfecf0999` |
| Production | KVM `srv1910783` `/var/www/radium-desk` named-file overlay; web root `755` |
| AppServiceProvider live | SHA-256 `28dd6921…` matches main (P-07-09-288) |
| Purchasing | Live `routes/web.php` registers `purchasing.*` (not on main) |
| Historical Orders | Live `historical-orders.*` (not on main) |
| Hardware realtime overlay | Live `data-live-updates-enabled` still 0 on Hardware; Ready Queue stays 1 |
| Hardware navigation overlay | Live WorkQueue uses `HardwareWorkspaceScope` Active/Shipped and chip **excludes Completed** |
| C360 | Drawer attributes on `/dashboard`; not built in this overlay set |
| B2B/date | Live `HardwareDashboardWorkspace::range()` still honors `from`/`to` |
| Shiprocket | Live `HardwareShipmentEligibility` keeps `HardwareConfigurableVariantDisplay` + `providerTrackStatus` |

## Current-main measurement (sqlite, 24 fulfilments + 24 service cases)

| Surface | Queries | Wall | HTML |
|---------|---------|------|------|
| `/dashboard` before | 101 | 64.3 ms | 83 KB |
| `/dashboard?queue=hardware` before | 190 | 99.7 ms | 141 KB |
| `/dashboard` after (main chip SQL) | 18 | 46.8 ms | 83 KB |
| `/dashboard?queue=hardware` after | 20 | 45.8 ms | 141 KB |

Do **not** treat these as production numbers. Production WorkQueue is not main’s WorkQueue.

## Surgical conflict

Main `workspaceTotal()` = all inspect rows.  
Live `workspaceTotal()` = inspect rows **minus** `isShippedWorkspaceItem()` (stage Completed).

Overlaying main WorkQueue would change the Hardware chip and drop Active/Shipped. Overlay uses a **production-patched** WorkQueue: same dashboard() signature and shipped exclusion; adds request-scoped `allRows` memo, eager inspect relations, `whereNotExists` RIN filter.

Live `HardwareShipmentEligibility` is **not** main (track fields + variant display). Overlay only the inspect N+1 hunks.

## Overlay set

From this branch (match origin/main + P-290 hunks):

- `app/Models/HardwareFulfilment.php`
- `app/Services/HardwareFulfilment/HardwareAwaitingFulfilmentQueue.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkflowService.php`
- `app/Services/HardwareFulfilment/HardwareSkuMapService.php`

From production + P-290 hunks only:

- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkQueue.php`
- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php`

Not overlaid: `AppServiceProvider`, `routes/web.php`, dashboard Blade, DashboardController, purchasing/historical, C360.

## Production

Named-file `install -m 644`. Backup first. Not `deskd`. Not `rsync --delete`.
