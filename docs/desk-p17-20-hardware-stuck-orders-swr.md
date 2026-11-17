# P-17-20 — Hardware Stuck Orders KPI (stale-while-revalidate)

## Verified baseline (pre-change)

| Item | Evidence |
|------|----------|
| Route | `GET /dashboard` → `DashboardController` |
| Widget | `dashboard.partials.admin-hardware-stuck-card` in `kpi-strip` (admin + `dashboard.hardware.view`) |
| Live aggregate | `HardwareDashboardWorkspace::stuckOrderSummary()` → `HardwareDashboardCountsCache::filterCounts()` → `HardwareNeedsActionSqlQuery::filterCounts()` (~11 COUNT queries) |
| P-11-43 deferral | SSR excluded live summary; deferred shell + `GET /dashboard/hardware/stuck-summary` + `hardware-stuck-summary-async.js` blocked UI with **Loading…** |
| Prod cost (doc) | `docs/P-11-42-dashboard-critical-path-audit.md` — **~11 queries, ~13–15s** on cold stuck-summary (VERIFIED reference, not re-measured on prod in P-17-20) |

## Reference widgets (verified)

### Total Active Cases

- **SSR:** `DashboardKpiAggregator::operationallyActiveCasesCount()` embedded in KPI strip (`total_active_cases`).
- **Refresh:** `resources/js/dashboard-live-counts.js` — stale-aware `GET /dashboard/live/counts?metric=active_cases` (no full `/dashboard/live`).
- **Ably:** `DashboardKpisUpdated` on `private-dashboard.{userId}` replaces KPI strip HTML; view-only metrics reconciled separately.

### Refunds (pending)

- Same pattern with `pending_refunds` / `DashboardKpiAggregator::refundStatusCounts()['pending']`.
- Phase-2 embed uses `refunds-workspace` soft switch (distinct from hardware stuck).

### Hardware Stuck Orders (bottleneck)

- **INFERRED UX bottleneck:** deferred card hid counts until slow JSON returned; user-visible **Loading…** state.
- **VERIFIED SQL bottleneck:** `HardwareNeedsActionSqlQuery::filterCounts()` runs independent counts per filter (mapping, awaiting serial, AWB, photo, shipping buckets).
- **Existing mitigation:** 30s `Cache::remember` on `hardware.dashboard.filter_counts.*` (warm hits skip SQL).

## Implemented SWR architecture

1. **`HardwareStuckOrdersSnapshotStore`** — hot snapshot + `last_known` payloads (`captured_at`, monotonic `version`).
2. **SSR** — `DashboardService::applyCachedHardwareStuckOrdersStats()` serves last-known snapshot without live SQL; stale flag in meta.
3. **Background refresh** — `RefreshHardwareStuckOrdersSnapshotJob` (unique, cache lock) via `HardwareStuckOrdersSnapshotRefresher`.
4. **API** — `GET /dashboard/hardware/stuck-summary` returns cached HTML immediately when stale; queues refresh when snapshot expired.
5. **Ably** — `HardwareStuckOrdersUpdated` on `private-dashboard.{userId}` with `html`, `summary`, `version`, `captured_at`.
6. **Client** — `hardware-stuck-summary-async.js` background revalidate; ignores older versions; no blocking reload on failed background fetch when counts already rendered.

## Cache keys

| Key | Purpose | TTL |
|-----|---------|-----|
| `hardware.dashboard.stuck_summary.snapshot` | Fresh snapshot | `DASHBOARD_HARDWARE_STUCK_SNAPSHOT_TTL_SECONDS` (default 30s) |
| `hardware.dashboard.stuck_summary.last_known` | Last good snapshot | default 86400s |
| `hardware.dashboard.stuck_summary.refresh` | Refresh lock | default 120s |
| `hardware.dashboard.filter_counts.{sha1}` | Per-aggregate SQL cache (unchanged) | `DASHBOARD_HARDWARE_COUNTS_TTL_SECONDS` |

## Scope preserved

- Count semantics, auth (`dashboard.hardware.view` + admin queues), categories/labels, Cashfree, Total Active Cases, Refunds — unchanged.
- Read-only reporting only.

## Production deployment

**NO** — implementation merged to main only; deploy gate not executed in P-17-20.