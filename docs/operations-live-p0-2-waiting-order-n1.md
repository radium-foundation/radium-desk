# Operations `/live` P0-2 — Waiting-State & Order N+1 Elimination

**Status:** Implemented locally; production measured via temporary patch then restored (not released)  
**Date:** 2026-08-07  
**Base:** production `v4.0.7` / `3a069f35`  
**Endpoint:** cold `GET /admin/operations/live` (full)  
**Canvas:** [`operations-live-p0-2-waiting-order-n1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-live-p0-2-waiting-order-n1.canvas.tsx)

Related: [operations-live-phase1-production-benchmark.md](./operations-live-phase1-production-benchmark.md)

---

## Verdict

The ~2100× `incident_waiting_states` and ~2100× `orders` N+1 queries on cold full `/live` were **not** from `DashboardSnapshotStore` / classifier (those already eager-load). They came from **IRA team performance quality scans**:

`IraOperationsBrainService::briefing` → `IraMemoryService` → `TeamPerformanceMetricsService::teamMetrics` → `buildQualityMetrics` → `Incident::slaStatus()` / `hasSlaPaused()` / `isPendingAdmin()` on collections loaded **without** `order` / `activeWaitingState`.

P0-2 eager-loads those relations (and batches active incidents once per request). Per-incident lazy SQL drops to **zero**.

---

## Root cause analysis

### What we traced

Production Kernel probe + `DB::listen` stack capture on cold full `/live`:

| Kind | Count | Origin |
|------|------:|--------|
| `incident_waiting_states … incident_id = ?` | **2117** | `Incident::hasSlaPaused` ← `slaStatus` ← `TeamPerformanceMetricsService::buildQualityMetrics` |
| `orders … id = ?` | **2109** | `Incident::isPendingAdmin` (`$this->order`) ← same path |

### What it is **not**

| Suspect | Finding |
|---------|---------|
| Eloquent lazy load on dashboard snapshot | Snapshot load: 1 waiting + 1 orders eager query; `relationLoaded` true for all ~1056 actives |
| `OperationsQueueClassifier` fallbacks | Classify-all on snapshot: **0** waiting/order by-id queries |
| Blade rendering | No waiting/order SQL in render path |
| Cashfree / health bundles | Separate (still present; out of P0-2 scope) |

### Exact call chain

```
OperationsDashboardController::live
 └─ IraOperationsBrainService::briefing   // ira_compact ∈ ALL_SECTIONS
     └─ IraMemoryService::capture / collectSnapshotData
         └─ teamPerformanceTotals
             └─ TeamPerformanceMetricsService::teamMetrics
                 └─ metricsFor → buildQualityMetrics  (per attendance-tracked user)
                     ├─ completedCasesQuery()->get()  // no with() → N+1 on slaStatus
                     └─ Incident::query()->active->get()->filter(slaStatus)  // no with() → N+1
```

`slaStatus()` always calls `hasSlaPaused()` (lazy `activeWaitingState()->first()` when unloaded) and `isPendingAdmin()` (lazy `$this->order`).

### Origin class

**Repository/service loop** (team metrics quality builder), not Blade, not DTO transform, not snapshot store failure.

---

## Fix (minimal)

**File:** `app/Services/Operations/TeamPerformanceMetricsService.php`

1. `completedCasesQuery()->with(['order', 'activeWaitingState', 'assignee'])->get()` before SLA evaluation.
2. Request-scoped `activeIncidentsForQuality()` loads operationally-active incidents **once** with the same relations; overdue counts filter in memory per user.

No UI, caching TTL, Cashfree, Blade, or polling changes. Response shape / SLA business rules unchanged.

**Test:** `OperationsDashboardPerformanceTest::test_team_performance_quality_scan_does_not_n_plus_one_waiting_or_orders`

---

## Before / after (production cold full `/live`)

Measured on `3a069f35` host: before = stock code; after = temporary file patch (then restored).

| Metric | Before P0-2 | After P0-2 | Δ |
|--------|------------:|-----------:|--:|
| Wall | 11.1–12.4 s | **10.52 s** | ~−5–15% |
| SQL count | **5561–5636** | **1344** | **−76%** |
| SQL time | 4.79 s | **3.50 s** | −27% |
| CPU (user+sys) | 7.7–8.8 s | **7.35 s** | ~−10–16% |
| Peak memory | 404–419 MB | **425 MB** | ~flat |
| `waiting` by-id N+1 | **2117** | **0** | −100% |
| `orders` by-id N+1 | **2109** | **0** | −100% |
| `incident_waiting_states` total | ~2120 | **10** | bulk only |
| Warm wall / SQL | ~0.47 s / 163 | ~0.50 s / 163 | unchanged |

Cold wall improves modestly because remaining cost is Cashfree integrity (~2 s SQL) + PHP/Blade. SQL count is the clear P0-2 win.

### Cold SQL after P0-2 (top)

| Pattern | Count | SQL ms |
|---------|------:|-------:|
| `orders.cashfree_payment_id` | 288 | 768 |
| other | 262 | 100 |
| leave_requests | 154 | 15 |
| orders_other (non N+1) | 153 | 254 |
| cashfree_webhook_logs | 121 | **1944** |
| work_sessions | 111 | 14 |
| incidents | 80 | 94 |
| company_holidays | 71 | 4 |
| audit_logs | 59 | 285 |
| incident_waiting_states | **10** | 6 |

---

## Remaining hotspot ranking (after P0-2)

| Rank | Hotspot | Evidence | Next |
|-----:|---------|----------|------|
| **1** | Cashfree integrity (IRA reasoning path) | webhook_logs 121 / ~1.9–2.7 s SQL | **P0-3** |
| **2** | PHP + Blade HTML rebuild | Cold wall ~10.5 s − SQL ~3.5 s ≈ **67% PHP** | Later |
| **3** | Team leave / session / holiday probes | 154+111+71 SQL | P1-3 |
| **4** | Audit / automation metrics | audit 59 / ~285 ms | Lower |
| **5** | Residual orders_other aggregates | 153 SQL (not by-id N+1) | Review if needed |

Waiting/order **by-id N+1 is closed**.

---

## Files changed

| File | Change |
|------|--------|
| `app/Services/Operations/TeamPerformanceMetricsService.php` | Eager-load + request-scoped active incident batch for quality/overdue |
| `tests/Feature/OperationsDashboardPerformanceTest.php` | N+1 regression test |

---

## Release note

Not shipped to production yet (probe used a temporary patch that was restored). Next release should be **v4.0.8** with changelog approval before `deskd`.

---

## Stop

P0-2 complete. Do **not** begin P0-3 (Cashfree) until this is released and accepted.
