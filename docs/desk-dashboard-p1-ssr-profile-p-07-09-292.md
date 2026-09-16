# Dashboard SSR remaining-time profile (production overlay)

**Prompt ID:** RadiumDesk-P-07-09-292  
**Date:** 2026-09-15  
**Type:** Investigation only. No application/schema/cache/deploy change.

## Environment

| Item | Value |
|------|--------|
| Host | KVM `srv1910783` `/var/www/radium-desk` |
| Git worktree | `cursor/dashboard-p1-perf-port-ade8` @ `de5b1adb` (ahead of `origin/main` `c738d635`) |
| Production SoT | Named-file overlay. Live WorkQueue SHA `ea4125ad…` (Active/Shipped chip, not git-main SQL COUNT). Live `HardwareDashboardWorkspace` SHA `c930002e…` (not git main). |
| User | `avinash@radiumbox.com` (Admin + Superadmin KPIs) |
| Method | CLI HTTP kernel, process-local `session.driver=array`. **No** `Cache::forget`, `optimize`, restart, or DB writes. |
| Dataset | 938 `hardware_fulfilments`, 64 shipments, 55,526 incidents. Hardware chip/table **936** Active rows. |

P-291 numbers used snapshot-store forget (colder). This gate did **not** invalidate caches, so wall times are lower than P-291 and closer to a warm operator request.

Targets **not** met: `/dashboard` under 2 s; Hardware 1.5–2 s.

## Request A — `/dashboard`

| Metric | Measured |
|--------|---------|
| Status | 200 |
| Wall | **2338 ms** |
| Queries | **542** |
| SQL cumulative | **673 ms** |
| Non-SQL (PHP/Blade/other) | **1665 ms** |
| HTML | 101 KB |
| Hardware chip | 936 |
| Live-updates | 1 |
| Hardware rows rendered | 0 |
| Recent service cases | page size 35, **1 row** materialized |

Isolated (extra live reads in the same CLI process):

| Section | Wall | Queries | SQL |
|---------|------|---------|-----|
| `OperationsWorkspaceResolver::resolve` | 10 ms | 2 | 1 ms |
| `DashboardService::dashboardChipCounts` (service-case chips **+** Hardware `chipCount` inspect) | **2679 ms** | **499** | 255 ms |
| `recentServiceCases` | 9 ms | 12 | 5 ms |
| Hardware `present` | 0 (not Hardware workspace) | 0 | 0 |
| `statsFor` (cold slow-scalar path) | 1025 ms | 17 | 356 ms |

Top HTTP-query costs on the single `/dashboard` request:

1. `notifications` select-all-for-user **320 ms** + unread `count` **51 ms** — `AppServiceProvider` navbar composer (`layouts.partials.navbar`).
2. Hardware inspect inside chip overlay — `HardwareDashboardWorkspace::chipCount` → `HardwareFulfilmentWorkQueue::workspaceTotal` → `allRows`/`inspect` on **938 fulfilments**. SQL for serials/shipments is already 1 query each; wall is PHP (~2.4 s in the isolated chip call).
3. `select status, COUNT(*) … from incidents` **25 ms** — KPI/`statsFor`.
4. 75× `incidents … FOR UPDATE` (**26 ms** total) — present on the HTTP request, not on the isolated chip call. Likely a GET-time lock in service-case/appointment mapping. SQL-cheap; still a write-lock on a read page.
5. 28× `commercial_service_restorations … limit 1` (**11 ms**) — `CommercialServiceRestorationService::activeFor` via `CommercialStateResolver` during chip/snapshot classification. True N+1, small SQL.

## Request B — `/dashboard?queue=hardware`

| Metric | Measured |
|--------|---------|
| Status | 200 |
| Wall | **3637 ms** |
| Queries | **228** |
| SQL cumulative | **407 ms** |
| Non-SQL | **3230 ms** |
| HTML | **2.12 MB** |
| Hardware rows rendered | **936** (same Active set as chip) |
| Live-updates | 0 |
| Scope counts | Active 936 / Shipped 2 |
| Filter counts (PHP, all computed) | all 936, ready 9, **exceptions 870**, pickup 57, scheduled 0 |

Isolated:

| Section | Wall | Queries | SQL |
|---------|------|---------|-----|
| `dashboardChipCounts` / `chipCount` | 1132 ms | 75 | 77 ms |
| `HardwareDashboardWorkspace::present` | **1212 ms** | **140** | 100 ms |
| `recentServiceCases` | skipped | 0 | 0 |
| `statsFor` (slow scalars already cached in-process) | 256 ms | 7 | 29 ms |

`present` loads **all 936 Active rows** into Blade. Filter chips for exceptions/pickup/scheduled are computed, but the default view still serializes every Active row. That HTML is not required to paint a first page.

Duplicate eager loads on Hardware HTTP (two WorkQueue instances: `DashboardService` chip vs controller `present`): 2× `hardware_fulfilments order by id desc`, 2× commerce/items/orders. Serial/shipment SQL = 2 (already eager).

63× `inventory_branches where id = ?` (**21 ms**) during `present`/`inspect` (`HardwareShipmentEligibility::physicalBranchCodes` / pickup). N+1, not the 3.6 s wall.

42× `Schema::hasTable(hardware_recovered_fulfilment_authorizations)` + 42× authorization `first()` (**29 ms**). N+1 from `HardwareFulfilmentEligibility` → `HardwareRecoveredFulfilmentAuthorization::tableExists`/`isAuthorized` with **no request memo**. SQL-cheap; doubles because inspect runs twice.

## Why `/dashboard` is still seconds after serial/shipment SQL dropped

Hardware serial/shipment SQL is no longer the Ready Queue cost. Ready Queue **still runs a full Hardware inspect** solely to print chip `(936)` via `DashboardService::dashboardChipCounts` → `overlayFilterCounts` → `workspaceTotal` (must exclude Completed; git-main SQL COUNT was not applied on production).

Plus navbar notifications (~371 ms SQL) and, on a cold slow-scalar cache, `COUNT(*) FROM audit_logs` (**195 ms**) for Superadmin `statsFor`.

Recent service cases are **not** the bottleneck (1 row, 9 ms).

## Why Hardware is still ~3.6 s with ~228 queries

SQL is **407 ms**. The rest is PHP:

- Inspect ~938 fulfilments **twice** (separate WorkQueue objects; `allRowsCache` does not span them).
- Blade of **936 rows / 2.12 MB** (870 exceptions included on the default Active/All table).

That matches “few queries, still slow.”

## Top 5 remaining bottlenecks (by measured wall)

| Rank | Bottleneck | Path | Family | Count | Cost | Likely optimization | Confidence |
|------|------------|------|--------|-------|------|---------------------|------------|
| 1 | Full Active Hardware inspect for the chip (and again for the table) | `DashboardController` → `DashboardService::dashboardChipCounts` → `HardwareDashboardWorkspace::chipCount` → `HardwareFulfilmentWorkQueue::workspaceTotal`/`allRows` → `HardwareShipmentEligibility::inspect`. Hardware page also `present()`. | PHP over 938 Eloquent graphs; SQL already mostly eager | 1× on Ready; **2×** on Hardware | Ready isolated chip **2.68 s** (255 ms SQL); Hardware present **1.21 s** + chip **1.13 s** | Production-safe **SQL Active count** (exclude Completed/Shipped) so Ready never inspects. Bind **one WorkQueue per request**. Do not overlay git-main COUNT-all. | High |
| 2 | SSR of 936 Hardware rows | `HardwareDashboardWorkspace::present` + `dashboard/partials/hardware-workspace.blade.php` | Blade/PHP; HTML 2.12 MB | 936 rows visible; 870 exceptions | ~1.3–3.2 s non-SQL on Hardware HTTP | Paginate/lazy-load default Active table; keep filter **counts** via SQL/aggregation | High |
| 3 | Navbar notifications | `AppServiceProvider` View composer `layouts.partials.navbar`: `unreadNotifications()->count()` + `notifications()->latest()->limit(10)` | `notifications` | 2 | **320 + 51 ms** on Ready; **157 + 40 ms** on Hardware | Limit/index `(notifiable_type, notifiable_id, created_at)`; avoid unbounded payload | High |
| 4 | Superadmin slow scalars (cold) | `DashboardService::slowChangingStatsFor` → `OperatorDashboardCache::slowScalars` → `AuditLog::count()` + `Order::count()` | `audit_logs` / `orders` | 1 each when cache miss | audit_logs **195 ms** isolated | Keep/extend TTL; or drop audit COUNT from dashboard stats | High (Ready/Superadmin only) |
| 5 | Recovered-auth + `hasTable` + branch lookups | `HardwareRecoveredFulfilmentAuthorization::tableExists`/`activeMatchingRow`; `physicalBranchCodes` | `information_schema`, `hardware_recovered_fulfilment_authorizations`, `inventory_branches` | 21–42 / 63 | **~15–30 ms** SQL | Memoize `hasTable`; `whereIn` authorizations; eager `inventorySerial.branch` consistently | High that they are N+1; **low** that they move the 2–4 s needle |

## Already efficient (do not “fix” first)

- Eager `hardware_fulfilment_serials` / `shipments` / `statutory_invoices` on the inspect query (1–2 queries, few ms).
- Ready Queue **row** load: 1 case, 9 ms.
- Workspace resolve / permissions (~10 ms).
- Shiprocket HTTP is not on this SSR path (inspect uses local `shipments` + `provider_track_status` already on the model).
- Purchasing / historical-orders / C360 drawers are not in these two SSR totals.

## Recommended next implementation gate

Highest value, overlay-safe:

1. **Active-only SQL chip** equivalent to `workspaceTotal` (exclude `isShippedWorkspaceItem` / Completed). Ready Queue should not call `inspect` at all.
2. **Request-scoped singleton** `HardwareFulfilmentWorkQueue` so Hardware `chipCount` + `present` share `allRowsCache`.
3. **Stop rendering 936 rows** (pagination or first-N). Out of P-291 scope; this is now the Hardware wall.
4. Navbar notification query/index.
5. Optional: memoize recovered-auth `hasTable`; batch restorations.

Do **not** overlay git-main WorkQueue. Do **not** `rsync --delete`. Do not change purchasing/historical/C360/B2B date/variant display except by inspection.

## Regression risks

- Chip must remain **936** Active (not 938 fulfilments).
- Default Hardware table membership must stay Active (not Shipped).
- Ready Queue live-updates=1 / Hardware 0.
- `inspect` still required for the visible Hardware table until pagination exists.

## Safe to implement?

**Yes** — a follow-up named-file overlay gate can proceed on items 1–3 with chip/table SHA checks. This prompt implemented nothing.
