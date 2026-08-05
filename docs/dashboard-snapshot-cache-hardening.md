# Dashboard Snapshot Cache — Architectural Hardening

**Date:** 2026-08-05  
**Follows:** [dashboard-snapshot-cache-safety-investigation.md](./dashboard-snapshot-cache-safety-investigation.md) (verdict: NOT READY)  
**Scope:** Shared operator dashboard snapshot cache only

---

## 1. Verdict after hardening

**READY WITH HARDENING (landed)**

The shared cache no longer stores Eloquent graphs. Close / resolve / reopen / transaction-close invalidate the snapshot without Hybrid Realtime, broadcast, or Reverb. Serialization round-trips on `file` and `database` stores (Redis when available).

---

## 2. What changed

| Area | Before | After |
|------|--------|-------|
| Shared payload | `Collection<Incident>` (Eloquent) | Plain array projection via `ActiveIncidentSnapshotPayload` |
| Cache key | `operator.dashboard.snapshot:v1` | `operator.dashboard.snapshot:v2` (v1 forgotten on write/forget) |
| Hit path | `instanceof Collection` | Validate array `{v, incidents}` → in-memory rehydrate |
| Close / resolve | Forget only if Hybrid broadcast ran | `ServiceCaseStatusService` always `DashboardSnapshotStore::forget()` |
| Reopen | Forget only via broadcast row update | Direct `forget()` then broadcast |
| Transaction close (`broadcast: false`) | Often no forget under Hybrid defaults | Covered by status-service `forget()` on close |
| Public APIs | — | `DashboardService` / `DashboardSnapshot` / Customer360 unchanged |

Slow scalars (`operator.dashboard.slow_scalars:v1` int array) unchanged.

---

## 3. Object graph (shared cache)

```
Cache["operator.dashboard.snapshot:v2"]  // PHP-serialized array on file/database/redis
└── array{
      v: 1,
      incidents: list<
        array{
          type: "model",
          alias: "incident"|"order"|"user"|…,
          attributes: array<string, scalar|null>,  // raw DB attributes
          relations: array<string, model|collection|null payload>
        }
      >
    }
```

Aliases are an allowlist (`ActiveIncidentSnapshotPayload::MODEL_ALIASES`). No FQCN object unserialize; `cache.serializable_classes=false` is safe.

Per request, `DashboardSnapshotStore` rehydrates `Incident` models (with relations) in memory and builds `DashboardSnapshot` as before.

---

## 4. Invalidation (Hybrid-independent)

| Mutation | Invalidation |
|----------|--------------|
| Assignment / reassignment | Unchanged — direct `forget()` in `ServiceCaseAssignmentService` |
| Close / resolve / any status change via `updateStatus` | **New** — always `dashboardSnapshotStore->forget()` |
| Reopen | **New** — always `forget()` before broadcast |
| Transaction / service-reference assign close | Via `closeActiveServiceCasesForOrder` → `updateStatus` → `forget()` |
| Waiting / hold / repair / broadcast paths | Unchanged |

TTL (15–30s) remains a backstop, not the primary freshness mechanism for status exits.

---

## 5. Performance

| Path | Behaviour |
|------|-----------|
| Cache miss | Full `Incident::query()->with([...])->get()` then encode + `Cache::put` |
| Cache hit | Decode array → Eloquent in memory — **no** incidents SELECT |
| Concurrent operators | Still share one key; last writer wins on put |

Existing cross-request reuse gains are preserved when the store round-trips arrays (production `database` / `redis` / `file`).

---

## 6. Tests

`tests/Feature/OperatorDashboardCacheTest.php`:

- Plain-array payload shape (not Eloquent)
- Round-trip on **file**, **database**, and **redis** (skip if Redis unavailable) with `serializable_classes=false`
- Zero incidents queries on warm hit after serializing round-trip
- Close / resolve / reopen forget with `broadcast: false`

```bash
php artisan test --filter=OperatorDashboardCacheTest
```

---

## 7. Code map

| Piece | Path |
|-------|------|
| Array encode/decode | `app/Services/Dashboard/ActiveIncidentSnapshotPayload.php` |
| Cache policy | `app/Services/Dashboard/OperatorDashboardCache.php` |
| Request rebuild | `app/Services/Dashboard/DashboardSnapshotStore.php` |
| Status invalidation | `app/Services/ServiceCaseStatusService.php` |
| Config note | `config/dashboard.php` |

---

## 8. Explicitly out of scope

- Customer360 / Email Intake / Team Activity caches
- Changing poll intervals or live payload shape
- Long-TTL or per-user snapshot keys
- Canvas deliverable

---

*End of hardening note.*
