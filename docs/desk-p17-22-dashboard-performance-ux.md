# P-17-22 — Dashboard performance & UX (coordinated)

## Dependency status

| Task | Status | Merge SHA | Scope in P-17-22 |
|------|--------|-----------|------------------|
| P-17-20 Hardware Stuck Orders SWR | **MERGED** (#316) | `0aae38e2f` | **Reused** — no duplicate implementation |
| P-17-21 Hardware progressive rows + page cache | **MERGED** (#317) | `b5df26fca` | **Reused** — baseline for workstream B |

## Workstream A — Hardware Stuck Orders SWR

**VERIFIED (code):** `HardwareStuckOrdersSnapshotStore`, `RefreshHardwareStuckOrdersSnapshotJob`, `HardwareStuckOrdersUpdated` on `dashboard.{userId}`, client `hardware-stuck-summary-async.js`, SSR last-known via `DashboardService::applyCachedHardwareStuckOrdersStats()`.

**UNKNOWN:** Production browser latency proof (same gate as P-17-20).

## Workstream B — Progressive tables

Delivered in P-17-21 (`hw_chunk_*`, page cache, prefetch). P-17-22 adds no further table changes.

## Workstream C — Inline service reference (Reference Number column)

**Before:** `dashboard.js` disabled the input and set save button label to `Saving…` until POST completed.

**After:** `inline-service-reference-optimistic.js` — immediate pending display on the row trigger, save generation guard for stale responses, editor reopened on failure. OEM inline completion keeps blocking UI (expiry + assignment gate).

**Backend:** Unchanged — `OrderTransactionService::assignTransactionId()` still commits before `DB::afterCommit` Ably broadcast.

## Workstream D — Search

| Surface | Change |
|---------|--------|
| Global navbar search | Debounced live search on dashboard (`350ms` default, min length `3`) with existing abort + request-id stale guards |
| Table quick filter | **Preserved** — `dashboard-filter.js` (`250ms` server debounce) |
| Hardware table search | **Preserved** — `hardware-dashboard-async.js` debounce + abort generation |
| `UniversalSearchService` | Constrained eager loads for listing fields (smaller hydrated graphs) |

## Production deployment

**NO** — merge to `main` only.