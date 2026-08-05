# Dashboard Snapshot Cache — Production Safety Investigation

> **Superseded (2026-08-05):** Hardening landed in [dashboard-snapshot-cache-hardening.md](./dashboard-snapshot-cache-hardening.md) (`operator.dashboard.snapshot:v2`, array payload). This investigation describes the pre-hardening `v1` design and is retained for audit history only.

**Date:** 2026-08-05  
**Scope:** Cross-request operator dashboard snapshot cache (`OperatorDashboardCache` + `DashboardSnapshotStore`)  
**Inputs:** [performance-sprint-dashboard.md](./performance-sprint-dashboard.md), [radium-desk-performance-audit.md](./radium-desk-performance-audit.md), [performance-sprint-regression-analysis.md](./performance-sprint-regression-analysis.md)  
**Method:** Code-path read of cache writers/readers, invalidation call sites, Hybrid Realtime gates, Laravel `cache.serializable_classes`, and a local serialize probe. **No code changes.**

---

## Verdict

**NOT READY**

The cache is not architecturally safe for production concurrent operator use as implemented.

1. It stores a live Eloquent `Collection` of `Incident` models (with deep eager relations) in the shared application cache.
2. Under Laravel’s default `cache.serializable_classes => false` and a serializing store (`database` / `file` / typical `redis`), cache **reads revive as `__PHP_Incomplete_Class`**, fail the `instanceof Collection` hit check, and never reuse the snapshot — while still **rewriting a large serialized blob on every miss**.
3. Invalidation for close / resolve / transaction-assign close is **coupled to Hybrid Realtime flags that default to off**. When those paths do not call `forget()`, TTL (15–30s) is the only protection — and that protection only matters if the store can actually return a live Collection (tests with `CACHE_STORE=array`, or a future allowlist change).

Slow-scalar caching (`array` of ints) is fine. The active-incident snapshot path is not.

---

## 1. Exactly what is cached?

### Keys

| Key | Type stored | TTL | Invalidation |
|-----|-------------|-----|--------------|
| `operator.dashboard.snapshot:v1` | Eloquent / Support `Collection` of `Incident` models | 15–30s (default 20) | `DashboardSnapshotStore::forget()` → `OperatorDashboardCache::forgetSnapshot()` |
| `operator.dashboard.slow_scalars:v1` | Plain PHP `array` of ints | 15–60s (default 30) | TTL only (`forgetSlowScalars` exists but is unused on mutations) |

Config: `config/dashboard.php` (`DASHBOARD_SNAPSHOT_CACHE_ENABLED`, TTL envs).  
Flag gate: `OperatorDashboardCache::snapshotCacheEnabled()` — when false, loader runs every time (request-scoped store still memoizes per HTTP request).

### Snapshot object graph (exact)

Built in `DashboardSnapshotStore::loadFresh()`:

```
Cache["operator.dashboard.snapshot:v1"]
└── Illuminate\Database\Eloquent\Collection   (also accepted as Illuminate\Support\Collection)
    └── [i] App\Models\Incident                 (Eloquent Model)
        ├── attributes (status, assigned_to_user_id, order_id, …)
        ├── order → App\Models\Order
        │   ├── deviceModel
        │   ├── transactionAssigner → User
        │   ├── legacyImporter
        │   └── refundRequests → Collection<RefundRequest>
        ├── refundRequests → Collection<RefundRequest>
        ├── creator → User
        ├── assignee → User
        │   └── roles → Collection<Role>
        ├── activeWaitingState → IncidentWaitingState|null
        ├── activeBusinessHold → BusinessHold|null
        └── supportAppointments → Collection<SupportAppointment>
```

Query filter: `whereIn('status', IncidentStatus::operationallyActive())`.

**Not stored in this key:** DTOs, scalar projections, IDs-only lists, pre-rendered HTML, presence/attendance, Extra Time, PI badges, Email Intake KPI payloads.

### Slow scalars object graph

```
Cache["operator.dashboard.slow_scalars:v1"]
└── array{
      total_orders: int,      // Order::count()
      total_users: int,       // User::count()
      audit_log_count: int    // AuditLog::count()
    }
```

### Classification

| Question | Answer |
|----------|--------|
| Eloquent models? | **Yes** — each active `Incident` plus nested models |
| Collections? | **Yes** — outer Eloquent/Support `Collection` |
| Arrays? | **Only** slow scalars |
| DTOs? | **No** |
| Serialized objects? | **Yes on serializing stores** — PHP `serialize` of the Collection graph; see §5 |

Hit check (must succeed for reuse):

```php
$cached = Cache::get(...);
if ($cached instanceof Collection) {
    return $cached;
}
```

---

## 2. Cache lifecycle

### Build

1. Any consumer calls `DashboardSnapshotStore::get()` (or `DashboardSnapshot::…` → store).
2. Request memo: `$this->snapshot ??= $this->loadFresh()`.
3. `loadFresh()`:
   - Remembers `OperationsQueueClassifier` classifications (request-scoped).
   - Calls `OperatorDashboardCache::rememberActiveIncidents($loader)`.
4. Loader (miss path): `Incident::query()->with([...eager...])->whereIn(status, operationallyActive())->get()`.
5. On miss (or incomplete hit): `Cache::put(SNAPSHOT_CACHE_KEY, $incidents, now()->addSeconds(ttl))`.

`DashboardSnapshotStore` is registered **scoped** in `AppServiceProvider` (one instance per HTTP request / container scope).

### Read

| Layer | Behaviour |
|-------|-----------|
| Request | Same `DashboardSnapshot` instance reused |
| Cross-request | `Cache::get` → only if `instanceof Collection` |

Consumers include `DashboardService` (SSR stats, live metrics, rows), `CaseQueueReadModel`, and any path that materializes `DashboardSnapshot`.

### Refresh

There is no partial refresh. Refresh = forget + next `get()` rebuilds the full active population.

### Invalidate

```
DashboardSnapshotStore::forget()
  → $this->snapshot = null
  → OperationsQueueClassifier::forgetClassifications()
  → OperatorDashboardCache::forgetSnapshot()
       → Cache::forget('operator.dashboard.snapshot:v1')
```

Facade helpers:

- `DashboardService::forgetSnapshot()` → store `forget()`
- `OperatorDashboardCache::forgetAll()` → snapshot + slow scalars (not used on normal mutations)

### Expire

TTL via `Cache::put(..., now()->addSeconds($this->snapshotTtlSeconds()))` with clamp `max(15, min(30, config))`.

### Call sites that clear the snapshot

**Direct `DashboardSnapshotStore::forget()` / `dashboardSnapshotStore->forget()`**

| Location | When |
|----------|------|
| `ServiceCaseAssignmentService` (assign / reassign / escalate path) | After ownership write — **always**, independent of Hybrid Realtime |
| `ServiceCaseAssignmentService::refreshAdminReadyMembershipAfterIdentityValidation` | Identity/admin-ready visibility |
| `IncidentWaitingStateService::wakeOwnerAfterCustomerResponse` | Customer wake |
| `OrderController` | Order ID change |
| `BusinessHoldService` | Activate / clear hold |
| `SerialWaitingRepairService` | Serial waiting repair mutation |
| `DashboardService::forgetSnapshot` | Called by broadcast service |

**Via `DashboardBroadcastService` → `dashboardService->forgetSnapshot()`**

| Method | Clears snapshot? | Notes |
|--------|------------------|-------|
| `broadcastRowUpdate` | Yes | Used by create, remark, queue membership, SLA row, etc. |
| `dispatchKpisUpdated` | Yes | From `kpisUpdated` / coalesce flush |
| `broadcastReferenceNumbersUpdated` | Yes | Behind Hybrid `REFERENCE_NUMBER` |
| `broadcastHybridIncidentUpdates` | Yes | Behind Hybrid `ASSIGNMENT` / `CLOSE_RESOLVE` |

**Not a snapshot invalidate:** slow-scalar TTL expiry; presence/attendance caches; Email Intake widget cache.

---

## 3. Invalidation coverage

### Covered (mutation → forget, reliable under default Hybrid=off)

| Operation | Path | Forget? |
|-----------|------|---------|
| Assignment / reassignment / escalate | `ServiceCaseAssignmentService` direct `forget()` | **Yes** |
| Admin ready after identity validation | Direct `forget()` + queue membership broadcast | **Yes** |
| Waiting clear / queue membership (when broadcast runs) | `serviceCaseQueueMembershipChanged` → `broadcastRowUpdate` | **Yes** |
| Customer wake | Direct `forget()` | **Yes** |
| Business hold on/off | Direct `forget()` | **Yes** |
| Serial waiting repair | Direct `forget()` | **Yes** |
| Reopen | `ServiceCaseStatusService::reopen` → `serviceCaseQueueMembershipChanged` | **Yes** |
| Case create / remark / non-close status (broadcast on) | Row update / KPI | **Yes** |
| Refund completion / repair (via `RefundRequestService` → `kpisUpdated`) | `dispatchKpisUpdated` | **Yes** (KPI path; may not refresh relations on cached incidents if forget missed elsewhere) |
| Order ID change | `OrderController` | **Yes** |

### Gaps / conditional (TTL-only or miss under default Hybrid flags)

Hybrid defaults in `config/system_settings.php`: `hybrid_realtime.assignment`, `close_resolve`, `reference_number` all **`default => false`**.

| Operation | Behaviour | Gap |
|-----------|-----------|-----|
| **Case close** (UI / status service, `broadcast=true`) | `serviceCaseClosed` **early-returns** if `CLOSE_RESOLVE` off. Waiting clear only broadcasts if an active waiting state existed. Non-waiting close → **no `forget()`**. | **Gap** — up to TTL if cache hits work |
| **Case resolve** | `serviceCaseResolved` gated on `CLOSE_RESOLVE` | **Gap** |
| **Transaction / service-reference assign** (`OrderTransactionService`) | Closes cases with `broadcast: false`. `transactionAssigned` gated on `REFERENCE_NUMBER`. `beginKpiCoalesce` + `flushKpiCoalesce` only forgets if `kpiRefreshPending` was set — close/broadcast paths do **not** set it when Hybrid is off. | **Critical gap** — no forget on the hot commercial-close path under defaults |
| **Scheduler / commands** that call `ServiceCaseStatusService::updateStatus` (e.g. `SyncCompletedOrdersServiceCases`, automation cleanup, Cashfree recovery) | Same close/resolve broadcast gates | **Gap** when Hybrid close/resolve off and no waiting-state side-effect |
| **IRA / Customer360** field edits that do not touch assignment, waiting, hold, or queue membership | No snapshot consumer of those fields in graph — **N/A** unless they change queue-visible relations | Partial / N/A |
| **Attendance / Presence / Extra Time / PI badges** | Outside snapshot key (separate services / live metrics) | **N/A** to this cache |
| **Email Intake KPI** | Own widget cache | **N/A** |
| **Automation that assigns** | Hits assignment `forget()` | Covered |
| **Broadcast refresh / Reverb** | Forgets only when broadcast methods that call `forgetSnapshot` actually run | Conditional on Hybrid / coalesce |
| **Slow scalars** after new orders/users/audits | TTL only (by design) | Acceptable lag ≤60s |

### Invalidation coverage summary

Assignment-centric mutations are solid (direct forget).  
**Status transitions that remove cases from `operationallyActive()` are not solid** under production-default Hybrid settings. The sprint note’s claim that invalidation rides “existing snapshot forget sites” is only true for a subset of mutations; close/resolve were never given a Hybrid-independent forget in `ServiceCaseStatusService`.

---

## 4. Concurrency

Global key: one `operator.dashboard.snapshot:v1` for **all** operators.

### Scenario A — Two users load dashboard simultaneously

Both miss (or both Incomplete_Class-miss) → both run full hydrate → both `Cache::put`. Last writer wins. No locking / singleflight. Harmless for correctness if both load the same DB state; wasteful under load.

### Scenario B — Operator A changes assignment; Operator B refreshes

Assignment path calls `dashboardSnapshotStore->forget()` in the same request as the write. Operator B should miss and rebuild. **Covered.**

### Scenario C — Operator A closes a case (Hybrid close/resolve off); Operator B refreshes

If the cache store can return a live `Collection` (array driver, or allowlisted unserialize): B can see the closed case still in the active snapshot until TTL (**15–30s**). KPI counts and rows derived from that snapshot are stale.

If the store returns `__PHP_Incomplete_Class` (default `serializable_classes=false` + database/file/redis serialize): B always rebuilds — **no stale hit**, but also **no cache benefit**.

### Scenario D — Operator A completes transaction ID / closes via `OrderTransactionService`

Under default Hybrid `REFERENCE_NUMBER=false` and `broadcast: false` close: **no forget** (see §3). Stale window = full TTL **if** hits work; otherwise perpetual miss.

### Is TTL the only protection?

| Store / config | Effective protection |
|----------------|----------------------|
| Serializing store + `serializable_classes=false` (prod-typical) | Hits never succeed → “protection” is accidental full rebuild; TTL unused for freshness |
| `CACHE_STORE=array` (phpunit) | Real hits → **TTL + forget sites**; forget gaps are real stale windows |
| Serializing store + classes allowed | Real hits → **TTL is the backstop** for every gap in §3 |

---

## 5. Stale object risk

### Confirmed probe (this investigation)

With `config('cache.serializable_classes') === false` and the **file** cache store:

- `Cache::put` of `collect([User model])` succeeds.
- `Cache::get` returns `__PHP_Incomplete_Class`.
- `instanceof Collection` is **false** → treated as miss.
- Raw `unserialize(..., ['allowed_classes' => false])` matches.

`.env.example` defaults `CACHE_STORE=database` and warns database cache is unsuitable for Platform ECC production (prefer Redis). Both database and file use PHP serialization; Redis typically does too unless configured otherwise. **Array** store (`phpunit.xml`) does **not** serialize — objects remain live references.

### Risks if hits ever succeed (array store today; allowlist tomorrow)

| Risk | Assessment |
|------|------------|
| Loaded relations | **Yes** — full eager graph frozen at put time |
| Mutated models | In-process mutation of a cached model instance can leak across requests on **array** store (same PHP process / shared array cache) |
| Hidden lazy loads | Detached models may lazy-load on access if relation missing → N+1 or wrong connection state |
| Detached Eloquent instances | **Yes** — not re-queried; attributes/relations can disagree with DB after mutations that missed `forget()` |
| Memory | Large graph duplicated into cache payload; concurrent puts amplify write size |
| Unexpected serialization | Incomplete class / silent miss; or, if allowlisted, serializing nested relations, casts, and pivots |

### Recommendation (investigation only — not implemented)

Store a **DTO / array snapshot** (or IDs + slim projection), never live Eloquent graphs:

- Prefer: list of incident IDs + version/hash, or a JSON-safe array shaped for KPI/row builders.
- Rebuild models per request from IDs if HTML/policy still needs Eloquent.
- Keep slow scalars as plain arrays (already correct).

---

## 6. Testing impact

### Facts

- `phpunit.xml` sets `CACHE_STORE=array` → snapshot cache **works** (by-reference objects).
- `OperatorDashboardCacheTest` flushes cache in `setUp`; base `TestCase` does **not**.
- Regression analysis: snapshot pollution is a **latent amplifier**, not the bulk of the 143-red report (~5–8 infra/dashboard slice).

### Who violates architectural expectations?

| Side | Verdict |
|------|---------|
| **Implementation** | Violates production architecture: caches non-serializable Eloquent graphs into a shared cache that Laravel intentionally refuses to revive; couples freshness to Hybrid broadcast for status exits. |
| **Tests** | Correctly use the app’s default cache driver for the suite (`array`). They do **not** violate architecture by “needing cache off.” They **fail to simulate production serialization**, so `OperatorDashboardCacheTest` can pass “cross-request reuse” while production never reuses. |

Do **not** disable the cache in tests as the primary fix. The product should store a serializable projection (or disable cross-request caching when the store cannot round-trip the payload). Tests should assert the **same round-trip contract** production uses (e.g. file/database store, or assert put/get type after serialize).

Cross-test pollution on `array` store is a harness gap secondary to the product bug; flushing by key in shared `TestCase` would be insulation, not a substitute for a safe payload.

---

## 7. Performance

### Intended (sprint note)

| Benefit | Claim |
|---------|-------|
| Queries saved | Skip full active-incident SELECT + eager loads across concurrent SSR/live polls within TTL |
| Concurrent benefit | Many operators / tabs share one hydrate |
| Slow scalars | Remove 1–3 full-table `COUNT(*)` from hot path |

### Actual under default serializing store + `serializable_classes=false`

| Dimension | Estimate |
|-----------|----------|
| Queries saved (snapshot) | **~0** — every request Incomplete_Class-misses and rehydrates |
| Memory / I/O | **Negative** — full graph serialize + `cache` table/Redis write on every dashboard/live touch |
| Concurrent benefit | **None** for snapshot; writers contend on the same key |
| Slow scalars | **Still beneficial** — plain arrays round-trip correctly |
| Risk | High architectural / correctness risk if serialization allowlist is loosened later without fixing invalidation; medium operational cost today (write amplification) |

### If serialization were fixed without fixing invalidation

Stale operational queues for up to **30s** after close/resolve/transaction-close under default Hybrid settings — unacceptable for concurrent operators.

---

## 8. Production readiness

### Verdict: **NOT READY**

| Criterion | Result |
|-----------|--------|
| Safe payload | Fail — Eloquent Collection in shared cache |
| Round-trip under prod cache defaults | Fail — Incomplete_Class / perpetual miss + rewrite |
| Invalidation completeness | Fail — close/resolve/transaction-close gaps when Hybrid defaults off |
| Concurrency correctness | Fail if hits work; “accidentally safe” if hits never work |
| Test/prod parity | Fail — array store hides the serialize break |
| Slow scalars alone | Ready (TTL lag acceptable) |

### What “READY WITH HARDENING” would require (guidance only)

1. Replace snapshot value with JSON-safe arrays / IDs (no Eloquent in `Cache::put`).
2. Call `DashboardSnapshotStore::forget()` from `ServiceCaseStatusService` on close/resolve/reopen **independently of Hybrid Realtime and broadcast flags** (same pattern as assignment).
3. Ensure `OrderTransactionService` close path always forgets (direct forget or guaranteed KPI pending), even when `broadcast=false` and Hybrid reference-number is off.
4. Add a test that round-trips the snapshot through a **serializing** cache store and asserts hit type.
5. Keep TTL as a backstop, not the primary freshness mechanism.

Until those land, leave `DASHBOARD_SNAPSHOT_CACHE_ENABLED=true` only if operators accept write amplification with no hit benefit — or turn the flag off in production until the payload and invalidation are fixed.

---

## Appendix — Code map

| Piece | Path |
|-------|------|
| Cache policy | `app/Services/Dashboard/OperatorDashboardCache.php` |
| Request + cross-request store | `app/Services/Dashboard/DashboardSnapshotStore.php` |
| Broadcast forget fan-in | `app/Services/DashboardBroadcastService.php` |
| Assignment forget | `app/Services/ServiceCaseAssignmentService.php` |
| Status close/resolve (Hybrid-gated broadcast) | `app/Services/ServiceCaseStatusService.php` |
| Transaction close (`broadcast: false`) | `app/Services/OrderTransactionService.php` |
| Config | `config/dashboard.php`, `config/cache.php` (`serializable_classes`) |
| Hybrid defaults | `config/system_settings.php` |
| Sprint intent | `docs/performance-sprint-dashboard.md` |
| Regression note | `docs/performance-sprint-regression-analysis.md` |

---

*End of investigation. No code was modified.*
