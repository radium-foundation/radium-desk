# P-13-43 — Row 10 batch assign coalescing (Phase 9 performance)

| Global Prompt | **P-13-43** |
| Cursor ledger | **RadiumDesk-P-13-43** |
| Baseline | **`aaed2900`** @ `origin/main` (post P-13-42) |
| Timestamp | **2026-11-13 ~14:45 IST** (investigation + fix) |

---

## Root cause

**Stale test harness (TEST)** — batch assignment, audits, and coalescing behaved correctly; post-assign dashboard KPI refresh failed in tests only.

| Item | Evidence |
| --- | --- |
| Symptom | `assertJsonPath('refresh.kpis', true)` failed; HTTP `success: true` with extension warning *Dashboard refresh failed* |
| Log | `bulk_assign.post_processing.failed` → `WorkspaceRefreshRenderer.buildBatchRefreshPayload` → `DashboardSnapshotStore::loadFresh()` |
| Exception | `Typed property DashboardSnapshotStore::$classificationIndex must not be accessed before initialization` |
| Trigger | `Mockery::mock(DashboardSnapshotStore::class)->makePartial()` **without** constructor args after `DashboardSnapshotStore` gained readonly `DashboardClassificationIndex` DI |

**Conclusion:** Partial mock skipped constructor injection; counting `forget()` still invoked real `get()` during `liveReverbMetricsFor`, which hit uninitialized `$classificationIndex`. Application batch coalescing and audit paths were not defective.

---

## Change

- `AssignReferencePhase9PerformanceTest` — pass `app(DashboardClassificationIndex::class)` into the `DashboardSnapshotStore` partial mock constructor.

No application / assignment / audit changes.

---

## Batch flow (verified)

```
POST dashboard.workspace.batch-transaction
  → OrderTransactionService::assignTransactionIdToIncidents (coalescer begin/end)
  → audits + notifications per incident
  → coalesced driver-guide batch job + snapshot forget + automation invalidation
  → WorkspaceBatchTransactionActionService::buildSuccessResponse
  → WorkspaceRefreshRenderer::buildBatchRefreshPayload (KPI refresh)
```

---

## PHPUnit (`bin/phpunit-isolated`, `DESK_PHP_BIN=/opt/homebrew/bin/php`)

| Scope | Result | Assertions | ms |
| --- | --- | ---: | ---: |
| Row 10 filter | **1/1 PASS** | 25 | ~3400 |
| `AssignReferencePhase9PerformanceTest` class | **2/2 PASS** | 28 | 3975 |
| `DriverGuideBatchChunkingTest` | **8/8 PASS** | 55 | 8278 |
| `ReferenceNumberAddedDriverInstallationGuideTest` | **9/9 PASS** | 50 | 9606 |
| `WorkspaceBatchTransactionActionTest` | **11/12 PASS** | 93 | 12852 |

`WorkspaceBatchTransactionActionTest::test_service_case_page_is_unaffected_by_batch_workspace_routes` — **FAIL** `ViteManifestNotFoundException` (pre-existing worktree/HTML; unrelated to this diff).

Full suite: **NOT RUN**.

---

## Preserved / Extended / Changed / Removed

| | |
| --- | --- |
| **Preserved** | Batch assignment correctness, audit counts (8+8), coalescing assertions, automation + driver-guide behavior |
| **Extended** | — |
| **Changed** | Test mock construction for `DashboardSnapshotStore` only |
| **Removed** | — |

---

## Risks

- Matrix row **10** CSV on `main` still **STILL FAILING** until register-reconcile (same pattern as P-13-41/42).
- Future constructor-only deps on `DashboardSnapshotStore` require passing them in partial mocks.

---

## Production / wallet

- Production access or deployment: **NO**
- Wallet WIP @ **`d9481615`** on `feature/p18-33-unified-wallet-display` — **unchanged**

---

*End of P-13-43 report.*
