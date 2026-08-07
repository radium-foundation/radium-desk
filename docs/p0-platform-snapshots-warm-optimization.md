# P0 — `platform:snapshots:warm` Optimization

**Status:** Phase 7 shipped (local); production re-measure pending deploy  
**Date:** 2026-08-07  
**Canvas:** [`p0-platform-snapshots-warm-optimization.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-platform-snapshots-warm-optimization.canvas.tsx)  
**Inventory:** [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) (Phase 7)  
**Remeasure:** [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md)

---

## Verdict

Cron CPU was dominated by rebuilding every zone every minute despite 120s/300s TTLs, plus duplicate probes (platform health 2×, executive KPIs 8×, overall health 2×, queue capture 3–4×). Phase 7 dedupes in-cycle work and skips warmers while caches are fresh — **amortized CPU for this command drops ~80%+** (target ≥70%).

| Metric | Before (prod) | After (est.) |
|--------|--------------:|-------------:|
| Wall / minute | **11.6 s** | **~1.9 s** amortized |
| Account CPU share | **~28%** | **~5%** |
| CPU reduction | — | **~84%** |

---

## Root cause

| Duplicate | Occurrences per warm |
|-----------|---------------------:|
| Platform health full probe | 2× |
| Executive KPI context (7 SQL) | **8×** |
| Overall health compute+store | 2× |
| Queue `jobs`/`failed_jobs` capture | 3–4× |
| Scheduler probe | 2× |

TTL ignored: cron every 60s vs P1 TTL **120s** / P3 TTL **300s**.

---

## Before / after (local)

| Run | Before wall | After wall | Before SQL | After SQL |
|-----|------------:|-----------:|-----------:|----------:|
| Cold `warmAll` | **205 ms** | **125 ms** (−39%) | **331** | **245** (−26%) |
| 2nd warm (caches fresh) | **66 ms** | **8 ms** (−88%) | **267** | **24** (−91%) |
| Executive zone alone | — | — | **79** (8×) | **16** (1×) |

---

## Amortized production estimate

| Minute | Behavior | Est. wall |
|-------:|----------|----------:|
| 0 | Full cold (deduped) | ~5.0 s |
| 1 | All fresh → skip | ~0.2 s |
| 2 | P1 only (120s) | ~2.0 s |
| 3 | Skip | ~0.2 s |
| 4 | P1 only | ~2.0 s |
| 5 | P1 + P3 (300s) | ~5.0 s |

5-minute average ≈ **1.9 s/min** vs **11.6 s** → **~84%** reduction.

---

## Files changed

| File | Change |
|------|--------|
| `app/Services/Executive/ExecutiveMetricsService.php` | In-request force memo |
| `app/Services/Platform/Warmers/PlatformHealthSnapshotWarmer.php` | Single probe |
| `app/Services/Platform/Warmers/CriticalAlertsSnapshotWarmer.php` | No duplicate overall-health |
| `app/Services/Platform/Warmers/PlatformSnapshotWarmingService.php` | Skip-when-fresh |
| `app/Console/Commands/WarmPlatformSnapshotsCommand.php` | Report skipped |
| `app/Infrastructure/Queue/QueueMetricsService.php` | Capture memo |
| `app/Services/Platform/Health/PlatformHealthSnapshotService.php` | Probe memo |
| `app/Services/Platform/PlatformAutomationOverviewService.php` | Reuse health snapshot |
| `app/Providers/InfrastructureServiceProvider.php` | Scoped queue metrics |
| `app/Providers/PlatformDashboardServiceProvider.php` | Scoped health snapshot |
| `tests/Feature/Platform/PlatformSnapshotWarmPerformanceTest.php` | Budgets |
| `tests/Unit/Executive/ExecutiveMetricsForceMemoTest.php` | 8→1 assert |

---

## Remaining bottlenecks

| Item | Why it remains |
|------|----------------|
| Cold email_operations / integration_health | Many COUNT/probes when TTL expires |
| `CACHE_STORE=database` | Cache ops are SQL — full inventory: [radium-desk-performance-audit.md §5](./radium-desk-performance-audit.md#5-cache), canvas [`p0-laravel-cache-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-laravel-cache-investigation.canvas.tsx) |
| Production re-benchmark | Confirm after deploy |

---

## Regression

`PlatformSnapshotWarmPerformanceTest` + `ExecutiveMetricsForceMemoTest` + related platform/queue tests: **26 passed**.
