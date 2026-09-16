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

Do **not** treat these as production numbers.

| Surface | Queries | Wall | HTML |
|---------|---------|------|------|
| `/dashboard` before | 101 | 64.3 ms | 83 KB |
| `/dashboard?queue=hardware` before | 190 | 99.7 ms | 141 KB |
| `/dashboard` after (main chip SQL) | 18 | 46.8 ms | 83 KB |
| `/dashboard?queue=hardware` after | 20 | 45.8 ms | 141 KB |

## Surgical conflict

Main `workspaceTotal()` = all inspect rows (SQL COUNT equivalent).  
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

## Production deploy

Backup: `/var/backups/radium-desk/overlays/p-07-09-291-20260915T171125Z`

Mechanism: `install -m 644 -o www-data -g www-data`. Not `deskd`. Not `rsync --delete`. Web root remained `755`. Then `optimize:clear` + `optimize`. Queue worker restarted.

| File | SHA-256 after overlay |
|---|---|
| `HardwareFulfilment.php` | `c9c83ab853d742e04c1004e5565e363fc6414bc5c6b609608c643dbe9c0b1bc2` |
| `HardwareAwaitingFulfilmentQueue.php` | `55d943128b4beb264f8c4c04f69f3f9b8bffad42d5080fb394c66e1dfbab6c04` |
| `HardwareFulfilmentWorkQueue.php` (prod-patched) | `ea4125adc36819a1750e49fd3f467940bcd52b90279bf1f536e248640ba6cb82` |
| `HardwareFulfilmentWorkflowService.php` | `a760ba100ac7c579a27b8b9e5e41114192cfff64e978b3a23a4d11c2ad6d7970` |
| `HardwareShipmentEligibility.php` (prod-patched) | `6f41bbb40108dbbd9b8f4f0440e302a18be9a6f212687740eae979bd2a7c6b7b` |
| `HardwareSkuMapService.php` | `06b7e48136c9995986e8d11eb03e1f1da1a266ce98d7ca1ff2accdea1e19e3cd` |

WorkQueue SHA does **not** match git main (`a8fb4719…` SQL-all-rows chip). That is intentional.

## Production authenticated SSR

In-process HTTP kernel as `avinash@radiumbox.com`. Snapshot store forgotten; no global `Cache::flush`.

| Surface | Before queries | After queries | Before wall | After wall | Semantics |
|---------|----------------|---------------|-------------|------------|-----------|
| `/dashboard` | 2602 | 534 | 5091 ms | 4709 ms | chip 936; live-updates=1; C360 attrs |
| `/dashboard?queue=hardware` | 4361 | 226 | 6222 ms | 3726 ms | 936 rows; order SHA `3adb92eb…` unchanged; live-updates=0; HTML 2,123,418 bytes identical |
| `/dashboard?queue=hardware&from=2026-09-07&to=2026-09-15` | 4358 | 233 | 6318 ms | 4412 ms | same 936-row SHA |

Hardware N+1:

- serial SQL 1878 → 2
- shipment SQL 1750 → 2
- invoice SQL 130 → 4

Hardware queries −94.8%. Hardware wall −40.1%. Ready queries −79.5%. Ready wall −7.5% (chip still inspects the live Active set; service-case path unchanged).

Unauth HTTPS TTFB after overlay: `/login` 0.72 s (200), `/dashboard` 0.73 s (302), `/dashboard?queue=hardware` 0.75 s (302).

`GET /dashboard/live?queue=action_required` 200 with `kpi_strip_html` + `service_case_filter_counts`. Purchasing and historical-order routes still registered.

## Rollback

Restore the six files from `/var/backups/radium-desk/overlays/p-07-09-291-20260915T171125Z`, then `optimize:clear` + `optimize`, then restart `radium-desk-queue-worker`. Do not overlay git-main WorkQueue (it would change the Hardware chip).

## Remaining risk

Ready Queue still pays one full Hardware inspect for the chip (~4.7 s / 534 queries). Main’s SQL COUNT chip was not applied on production because it would include Completed/Shipped. Remaining Hardware N+1: recovered-authorization exists checks and some `inventory_branches` lookups. Pagination was out of scope.
