# Performance Sprint — Operator Dashboard Quick Wins

**Date:** 2026-08-05  
**Source:** [radium-desk-performance-audit.md](./radium-desk-performance-audit.md)  
**Scope:** Dashboard only — snapshot cache, slow scalar deferral, fast/slow split  
**Out of scope:** Team Activity, Customer360, Email Intake, UI redesign

---

## 1. Verdict

Operator dashboard hot path no longer re-runs full-table `Order` / `User` / `AuditLog` `COUNT(*)` on every SSR and live poll. Active-incident hydrate is shared across requests for 15–30 seconds (still invalidated on existing snapshot forget sites). Live refresh exposes an additive `fast` / `slow` payload split while keeping the existing JSON keys for clients.

---

## 2. What changed

| Item | Before | After |
|------|--------|-------|
| Active-incident snapshot | Request-scoped only (`DashboardSnapshotStore`) | Request store + cross-request cache `operator.dashboard.snapshot:v1` (15–30s) |
| `Order::count()` | Every `statsFor()` | Cached in `operator.dashboard.slow_scalars:v1` |
| Superadmin `User::count()` / `AuditLog::count()` | Every `statsFor()` for superadmin | Same slow-scalar cache |
| Live `/dashboard/live` payload | Flat metrics + rows | Same flat keys + `fast` / `slow` groups |

Functionality is unchanged: KPI strip, filter counts, case rows, and admin scalars still render the same values (scalars may lag by up to the configured TTL).

---

## 3. Fast vs slow data

### Fast-changing (operator work)

Built by `DashboardService::fastChangingStatsFor()`:

- Online users / online count
- Open / waiting / SLA / queue KPIs from snapshot
- Support-agent action cards + next appointment
- Refund / approval aggregates (role-gated)
- Email Intake widget (unchanged in this sprint; already has its own widget cache)
- Service-case filter counts and row HTML on live refresh

### Slow-changing (admin table totals)

Built by `DashboardService::slowChangingStatsFor()` via `OperatorDashboardCache::slowScalars()`:

- `total_orders`
- `total_users` (superadmin)
- `audit_log_count` (superadmin)

These are removed from the uncached hot path; TTL refresh keeps them available in the UI.

---

## 4. Cache keys and TTLs

| Key | Contents | Default TTL | Clamp | Invalidation |
|-----|----------|-------------|-------|--------------|
| `operator.dashboard.snapshot:v1` | Active incidents + eager relations | 20s | 15–30s | `DashboardSnapshotStore::forget()` (assignment, waiting-state, broadcast refresh, etc.) |
| `operator.dashboard.slow_scalars:v1` | Order / User / AuditLog counts | 30s | 15–60s | TTL only |

Config (`config/dashboard.php` / `.env`):

```bash
DASHBOARD_SNAPSHOT_CACHE_ENABLED=true
DASHBOARD_SNAPSHOT_CACHE_TTL_SECONDS=20
DASHBOARD_SLOW_SCALARS_CACHE_TTL_SECONDS=30
```

Set `DASHBOARD_SNAPSHOT_CACHE_ENABLED=false` to fall back to request-scoped hydrate only.

---

## 5. Code map

| Piece | Path |
|-------|------|
| Cache policy + loaders | `app/Services/Dashboard/OperatorDashboardCache.php` |
| Snapshot store (+ cross-request) | `app/Services/Dashboard/DashboardSnapshotStore.php` |
| Fast / slow stats + live metrics | `app/Services/DashboardService.php` |
| Live JSON split | `app/Http/Controllers/DashboardLiveController.php` |
| Config | `config/dashboard.php` |
| Tests | `tests/Feature/OperatorDashboardCacheTest.php` |

---

## 6. Live payload shape (additive)

Existing top-level keys remain authoritative for current JS:

- `kpi_strip_html`, `online_*`, `service_case_filter_counts`, `rows`, …

New optional groups:

```json
{
  "fast": {
    "online_count": 3,
    "online_users": [],
    "service_case_filter_counts": {},
    "next_appointment": null,
    "rows": [],
    "incident_ids": [],
    "total_count": 0,
    "has_more": false,
    "loaded_count": 0,
    "service_cases_empty": true,
    "service_cases_empty_html": "…"
  },
  "slow": {
    "total_orders": 1200,
    "total_users": 40,
    "audit_log_count": 90000
  }
}
```

No frontend redesign in this sprint — clients may ignore `fast` / `slow`.

---

## 7. Expected impact

| Change | Gain | Risk |
|--------|------|------|
| Snapshot cache 15–30s | Cuts repeated active-incident SELECT + eager loads across concurrent polls / users | Medium — queue membership can lag up to TTL; mitigated by existing `forget()` on mutations |
| Slow scalar cache | Removes 1–3 full-table COUNTs from every dashboard SSR / live poll | Low — totals may lag up to TTL |
| Fast/slow split | Enables future surgical live updates without full KPI strip HTML | None (additive) |

---

## 8. Explicitly not done (this sprint)

- Lazy Team Activity SSR
- Email Intake attention precompute / read-path audit writes
- Customer360 drawer / IRA / timeline work
- SQL-paginated case projection (major)
- Frontend redesign or poll interval changes
- Canvas deliverable

---

## 9. Verification

```bash
php artisan test --filter=OperatorDashboardCacheTest
php artisan test --filter=OperationsDashboardPerformanceTest::test_dashboard_snapshot_store_reuses_single_incident_load_per_request
```

Manual: load `/dashboard` as admin and superadmin; confirm KPI strip and live refresh still update case/online metrics; confirm Total Users (JSON KPI path) still appears for superadmin after warm cache.

---

*End of sprint note.*
