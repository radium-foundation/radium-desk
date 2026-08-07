# Operations `/live` Phase 1 — Production Benchmark

**Status:** Validate only (no further optimization)  
**Date:** 2026-08-07  
**Deploy:** `v4.0.7` / `3a069f35`  
**release.json:** `version=4.0.7`, `build=3a069f35`, `deployed_at=2026-08-07T22:35:12+05:30`  
**Endpoint:** `GET /admin/operations/live` (full, no `?groups`)  
**Probe:** HttpKernel as user id=1 · cold = `Cache::flush` + snapshot forget · warm = immediate second call  
**Canvas:** [`operations-live-phase1-production-benchmark.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-live-phase1-production-benchmark.canvas.tsx)

Related: [operations-live-phase1-requested-bundles.md](./operations-live-phase1-requested-bundles.md) · [p0-operations-live-architecture-investigation.md](./p0-operations-live-architecture-investigation.md)

---

## Verdict

Phase 1 is live on production and **worked**, with asymmetric impact:

| Path | Result |
|------|--------|
| **Warm full `/live`** | Large win — **~9 s → ~0.47–0.49 s**, SQL **~550 → 163** |
| **Cold full `/live`** | Wall win — **~20.7 s → 12.4 s (−40%)**; SQL count **almost flat** (~5636) |

Cold SQL remains dominated by waiting-state/order N+1 plus residual Cashfree integrity work pulled by **IRA reasoning** (`RuleBasedReasoningProvider` → Cashfree health widget), not by the removed `allBundles()` health shells.

Do **not** start Phase 2 until this ranking is accepted.

---

## Deploy notes

- `deskd` completed successfully
- Workers: `supervisorctl restart radium-reverb radium-queue` attempted by deploy
- Caches rebuilt (`optimize:clear` + `optimize`)
- Health check passed

---

## Before / after (production)

### Full `GET /admin/operations/live`

| Metric | Baseline cold | Phase 1 cold | Baseline warm | Phase 1 warm |
|--------|-------------:|-------------:|-------------:|-------------:|
| Wall | 20.6–20.8 s | **12.41 s** | ~9.1–9.7 s | **0.47–0.49 s** |
| SQL count | 5646–5966 | **5636** | ~547–550 | **163** |
| SQL time | ~7.1 s | **4.79 s** | ~3.1 s | **0.33 s** |
| CPU (user+sys) | n/m | **8.75 s** | n/m | **0.15–0.17 s** |
| Peak memory | ~394 MB | **419.5 MB** | ~126 MB | **~58 MB** (isolated warm process) |
| Response bytes | ~151–155 KB | **126 KB** | same shape | **126 KB** |

### Deltas vs baseline

| Path | Wall | SQL count |
|------|-----:|----------:|
| Cold | **−40%** (−8.3 s) | ~flat (−10 to −330) |
| Warm | **−95%** (−8.6 s) | **−70%** (−387) |

Warm improvement is the clearest Phase 1 signal: full-refresh cache payload no longer pays `allBundles()`, and IRA/section caches hit on the second call.

---

## Cold SQL top patterns (Phase 1)

| Pattern | Count | SQL ms | Notes |
|---------|------:|-------:|-------|
| `orders` (non-id / other) | 2249 | 493 | Residual classify / order access |
| `incident_waiting_states` | 2114 | 204 | N+1 (P0-2 candidate) |
| `orders.cashfree_payment_id` | 288 | 808 | Integrity / exists family |
| `cashfree_webhook_logs` | 121 | **2702** | Dominant SQL **time** |
| `leave_requests` | 166 | 20 | Team availability |
| `work_sessions` | 117 | 17 | Team availability |
| `incidents` | 92 | 112 | SI / IRA |
| `company_holidays` | 82 | 7 | Team availability |
| `audit_logs` | 59 | 298 | Notification/automation metrics |
| `cache` table | 22 | 2 | DB cache driver |

Cashfree integrity is **still present on cold full refresh** even though `cashfree_health` / `integration_health` bundles were removed from section mapping. Remaining path: IRA briefing → `RuleBasedReasoningProvider` → `OperationsCashfreeHealthService::widget()`.

---

## Updated hotspot ranking (after Phase 1)

| Rank | Hotspot | Evidence | Next phase |
|-----:|---------|----------|------------|
| **1** | Waiting-state + order N+1 during classify/SLA | 2114 + ~2249 SQL on cold | P0-2 |
| **2** | Cashfree integrity via IRA reasoning (not OCC health shell) | webhook_logs 121 / **2.7 s** SQL | P0-3 (scope IRA path too) |
| **3** | PHP + Blade HTML rebuild | Cold wall 12.4 s − SQL 4.8 s ≈ **61% PHP** | Later / JSON metrics |
| **4** | Team leave / session / holiday probes | 166+117+82 SQL | P1-3 |
| **5** | Audit-log / metrics aggregations | audit 59 / 298 ms | Lower |
| **6** | Always-refresh partial poll cost | Not remeasured this run; Phase 1 targets full refresh | P1-1 cadence |

### Confirmed Phase 1 wins

- Skipped unused OCC health bundles on full refresh (`cashfree_health`, `integration_health`, `radiumbox_health`, `gmail_health`, `system_health` from section map)
- Warm full refresh collapsed from multi-second to sub-second
- Response still returns all expected HTML section keys
- On-demand health groups unchanged (not re-probed here)

### Not fixed by Phase 1

- Cold SQL count (~5600)
- Waiting/order N+1
- Cashfree integrity when IRA compact is part of full refresh
- Blade HTML rebuild cost

---

## Stop

No further code changes in this pass. Next optimization phase should target **P0-2 (N+1)** and/or **Cashfree on the IRA reasoning path**, after accepting these production numbers.
