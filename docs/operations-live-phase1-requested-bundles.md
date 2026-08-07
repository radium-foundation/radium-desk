# Operations `/live` Phase 1 — Requested-Bundle Execution

**Status:** Implemented (stop for benchmark before Phase 2)  
**Date:** 2026-08-07  
**Base:** production `v4.0.6` / `e443e20`  
**Endpoint:** `GET /admin/operations/live`  
**Canvas:** [`operations-live-phase1-requested-bundles.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-live-phase1-requested-bundles.canvas.tsx)

Related: [p0-operations-live-architecture-investigation.md](./p0-operations-live-architecture-investigation.md)

---

## Verdict

Phase 1 removes the full-refresh shortcut that forced `allBundles()` / `build()` whenever sections equaled `ALL_SECTIONS`. Full refresh now builds **only the bundles declared for the requested sections**.

Static `health_status` no longer pulls Cashfree, integration health, RadiumBox, Gmail, or system health.

API JSON shape (`generated_at`, `groups`, `html`), UI markup, permissions, and business logic for rendered sections are unchanged. Explicit lazy groups (`health_cashfree`, `health_radiumbox`, `health_telegram`) still build their bundles on demand.

---

## What changed

| Before | After |
|--------|-------|
| `bundlesForSections(ALL_SECTIONS)` → `allBundles()` | Map each section via `SECTION_BUNDLES` only |
| `dashboardDataForSections(full)` → `build()` | Always `buildForSections($sections)` |
| Full refresh built 17 bundles | Full refresh builds 12 requested bundles |

### Bundles skipped on full refresh

- `cashfree_health`
- `integration_health`
- `radiumbox_health`
- `gmail_health`
- `system_health`

### Bundles still built for `ALL_SECTIONS`

`support_intelligence`, `ivr_analytics`, `queue_metrics`, `team_availability`, `team_telegram_status`, `notification_metrics`, `automation_metrics`, `cashfree_device_enrichment`, `missing_serial_automation`, `recent_notification_failures`, `recent_automation_activity`, `recent_ira_messages`

`build()` / `allBundles()` remain for profiling / explicit full-widget tooling only — not the `/live` path.

---

## Before / after metrics

### Local service build (3-run avg, cold cache each run)

| Metric | Legacy `build()` / allBundles | Phase 1 requested bundles | Delta |
|--------|-----------------------------:|--------------------------:|------:|
| Wall | 49.2 ms | 17.8 ms | **−31.4 ms (−64%)** |
| SQL count | 100 | 46 | **−54 (−54%)** |
| SQL time | 32.4 ms | 11.6 ms | **−20.8 ms** |
| CPU (user+sys) | 21.5 ms | 8.3 ms | **−13.2 ms (−61%)** |

### Local HTTP `GET /admin/operations/live` (PHPUnit benchmark)

| Path | Before Phase 1 | After Phase 1 |
|------|---------------:|--------------:|
| Full live wall | 186.7 ms | 81.6–82.1 ms |
| Full live SQL | 246 | 232 |
| Partial (first paint) wall | 10 ms | ~9 ms |
| Partial SQL | 21 | 21 |

Local DB has little/no Cashfree integrity volume, so HTTP SQL delta is modest. Service-level compare still shows clear removal of the five unused health bundles.

### Production expectation (from architecture probe)

Production cold full refresh was **~5646–5966 SQL / ~20.6–20.8 s**, dominated by Cashfree integrity (`cashfree_webhook_logs` chunked IN, `exists` storms) plus duplicate integration-health Cashfree work — both pulled only because full refresh called `allBundles()`.

Phase 1 skips those bundles on the normal full poll. Expected production cold full-refresh improvement: **on the order of the investigation’s P0-1 estimate (≈40–60% cold wall)** once measured on production after deploy. **Do not treat local ms as the production savings figure.**

| Layer | Production before (probe) | Phase 1 expected |
|-------|--------------------------:|------------------:|
| Full `/live` wall (cold) | 20.6–20.8 s | Large drop (Cashfree/integration off path) |
| Full `/live` SQL (cold) | 5646–5966 | Large drop (Cashfree N+1/chunk storm off path) |
| Partial always-refresh | ~9 s warm floor | Unchanged by Phase 1 |

---

## Files changed

| File | Change |
|------|--------|
| `app/Services/Operations/OperationsDashboardSectionBundles.php` | Remove `ALL_SECTIONS → allBundles()` shortcut |
| `app/Services/Operations/OperationsDashboardService.php` | Always `buildForSections()` in `dashboardDataForSections()` |
| `tests/Feature/OperationsDashboardPerformanceTest.php` | Phase 1 bundle/query regression tests; deferred empty-bundle SQL = 0 |

---

## Preserved contracts

- Response schema: `{ generated_at, groups, html }`
- Section HTML keys for full refresh unchanged
- Middleware / `operations-dashboard.view` unchanged
- On-demand `?groups=health_cashfree|health_radiumbox|health_telegram` still loads those widgets
- `build()` / `buildProfiled()` still available for all-bundle profiling

---

## Rollback plan

1. Revert the two service files to restore:
   - `bundlesForSections`: `if ($sections === ALL_SECTIONS) return allBundles();`
   - `dashboardDataForSections`: `isFullRefresh ? build() : buildForSections()`
2. Revert the Phase 1 tests in `OperationsDashboardPerformanceTest.php` (optional).
3. No migrations, config, or UI assets involved — deploy revert only.
4. Cache key `operations:dashboard:latest:v2` can keep serving; after revert, full payloads refill within 30s TTL.

```bash
git checkout HEAD -- \
  app/Services/Operations/OperationsDashboardSectionBundles.php \
  app/Services/Operations/OperationsDashboardService.php \
  tests/Feature/OperationsDashboardPerformanceTest.php
```

(Or revert the Phase 1 commit once committed.)

---

## Out of scope (next phases)

- N+1 waiting_state / order classify fixes (P0-2)
- Cashfree integrity memo / batched probe (P0-3)
- Polling cadence splits (P1-1 / P1-2)
- Team leave/holiday bulk queries (P1-3)

**Stop here for production benchmarking before Phase 2.**

---

## Verification

```bash
php artisan test --filter='OperationsDashboardPerformanceTest|OperationsDashboardBenchmarkTest|ControlCenterFirstPaintTest'
```

18 passed (focused suite). Pre-existing failures in `OperationsDashboardTest` about “Loading integration health” / Meta Flow on system tab are unrelated to Phase 1 (confirmed failing on pre-change code).
