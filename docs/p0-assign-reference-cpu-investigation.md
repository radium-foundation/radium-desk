# P0 Assign Reference — Production CPU Investigation

**Status:** Investigation complete → **Phase 9 implemented** (batch coalescing)  
**Date:** 2026-08-07  
**Host:** `desk.radiumbox.com` via `tools/config.sh`  
**Method:** Code path audit + production `audit_logs` + `queue-worker.log` + Phase 9 local tests  
**Canvas:** [`p0-assign-reference-cpu-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-assign-reference-cpu-investigation.canvas.tsx)

Related: [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) (Phase 9) · [p0-production-remeasure-after-optimizations.md](./p0-production-remeasure-after-optimizations.md)

---

## Verdict

CPU spikes immediately after Assign Reference (especially batch) are **caused by this action**, not by Customer360 / RadiumBox / Bonvoice.

**Pre–Phase 9 #1 Assign-attributable consumer:** `SendServiceReferenceDriverGuideJob` — **5–8s wall per order**, ~**1:1** with every successful assign (598 assigns → 597 driver guides in last 12h). Last 200 queue DONE lines: **119 DriverGuide (59%)**.

**Pre–Phase 9 #2 Sync amplifier:** batch `assignTransactionId` loop closes every open case, forgets the operator dashboard snapshot on each close, dirties automation Health+Validation (full rebuild on next cron), then renders N row Blades + recomputes KPIs + fans out `ReferenceNumbersUpdated`.

**Phase 9:** Batch path now coalesces DriverGuide → **1 job**, snapshot forget → **1×**, automation dirty → **1×**, notifications → **1 flush pass** (same per-order notification count). Single-assign path unchanged. See [Phase 9 in inventory](./p0-production-cpu-request-inventory.md#phase-9--batch-assign-reference-coalescing-implemented).

---

## Entry points

| Route | Controller | Role |
|-------|------------|------|
| `POST /dashboard/workspace/batch-transaction` | `DashboardWorkspaceActionController::batchTransaction` | **Primary batch UI** |
| `POST /orders/{order}/transaction` | `OrderTransactionController::store` | Single assign |
| `POST /dashboard/transactions/bulk` | `OrderTransactionController::bulkStore` | Legacy JSON bulk |

Core write: `OrderTransactionService` (not `ServiceCaseAssignmentService` — that only shapes Ready-queue remove_rows in the response).

---

## Timeline

```
Submit
  │  POST batch-transaction / orders.transaction.store
  ▼
HTTP request (SYNC)
  │  FOR EACH ORDER:
  │    commercial gate → verification → business hold
  │    DB txn: order update, audits (service_reference.assigned + transaction.assigned)
  │    close all open cases (nested txns)
  │      → DashboardSnapshotStore::forget
  │      → AutomationOperationsSnapshotInvalidator::markCaseOrOrderChanged
  │        (Health + Validation + RecentEvents)
  │      → waiting clear + complete scheduled appointments
  │    afterCommit: TransactionCompletedNotification (DB) → NotificationCreated Reverb
  │    afterCommit: SendServiceReferenceDriverGuideJob::dispatch
  │  THEN (batch):
  │    render service-case-row × N
  │    liveReverbMetricsFor (KPI strip + filter counts)
  │    transactionsAssigned → ReferenceNumbersUpdated fan-out
  ▼
HTTP response
  │  replace_rows / remove_rows / kpis_html
  ▼
Queue (ASYNC — notifications)
  │  SendServiceReferenceDriverGuideJob × orders
  │    → WhatsApp driver_installation_guide (Interakt HTTP sync in worker)
  │    → Email channel
  │    → audit service_reference.driver_guide_sent
  │  Wall: 5–8s / job · serial worker ≈ 3–5 min for N≈35
  ▼
Client (other dashboards)
  │  Echo ReferenceNumbersUpdated → GET /dashboard/live/rows?ids[]=…
  ▼
Scheduler (≤60s / 15m)
  │  automation:snapshot — dirty Health/Validation → rebuild
  │  automation:snapshot --reconcile — full rebuild every 15m
  ▼
Final state
     Orders locked · cases Closed · guides sent · snapshots refreshed
```

---

## Checklist answers

| # | Question | Finding |
|---|----------|---------|
| 1 | Sync ops after Assign Reference | Full cascade in request (see timeline) |
| 2 | Jobs dispatched | **Only** `SendServiceReferenceDriverGuideJob` (× orders) |
| 3 | Events fired | No Laravel domain events. Audit strings + Reverb broadcasts |
| 4 | Notifications | `TransactionCompletedNotification` (database, sync afterCommit); driver guide WA+Email (async job) |
| 5 | Dashboard refreshes | Response KPI/rows; `ReferenceNumbersUpdated` → clients fetch `/dashboard/live/rows` |
| 6 | Customer360 refreshes | **Not in path** |
| 7 | Snapshot invalidations | Operator dashboard forget per case close; automation dirty flags |
| 8 | Automation rebuilds | Health/Validation dirty → full rebuild on cron (indirect) |
| 9 | Queue jobs | DriverGuide only from this path |
| 10 | Driver Guide generation | Queued; dominant post-submit CPU |
| 11 | RadiumBox sync | **Not triggered** by assign |
| 12 | Bonvoice actions | **Not in path** |

---

## Top CPU consumers caused specifically by Assign Reference

| Rank | Consumer | Phase | Wall / scale | Evidence |
|-----:|----------|-------|--------------|----------|
| 1 | `SendServiceReferenceDriverGuideJob` | Queue | **5–8s / order** | 119/200 recent DONE; 597/598 assigns→guides (12h) |
| 2 | Per-order `assignTransactionId` | HTTP sync | ~0.8–1.5s / order (est.) | Peak minutes 35–42 assigns in 28–37s (2 actors) |
| 3 | Case close → snapshot forget + automation dirty | HTTP sync | × closed incidents | `ServiceCaseStatusService::updateStatus` |
| 4 | Post-batch Blade rows + `liveReverbMetricsFor` | HTTP sync | N rows + KPI | `WorkspaceRefreshPolicy` `refreshKpis: true` for BatchTransaction |
| 5 | `ReferenceNumbersUpdated` + client `/dashboard/live/rows` | Sync + clients | O(users×cases) + N GETs | ~12 `incidents.view` users |
| 6 | `TransactionCompletedNotification` + bell broadcast | afterCommit | × recipients × orders | `BroadcastNotificationCreated` |
| 7 | `automation:snapshot` rebuild | Scheduler | ~5–15s when dirty | Reconcile sample **4945ms**; dirty `health,validation,recent_events` |

### Cost model (one batch ≈ 35 orders, serial worker)

| Work | Wall seconds (modeled) |
|------|----------------------:|
| HTTP sync | ~35 |
| DriverGuide queue (35 × 6s) | **210** |
| Automation rebuild | ~10 |
| Client live/rows fan-out | ~8 |
| **Assign-triggered total** | **~263** |

DriverGuide ≈ **80%** of Assign-triggered wall for a 35-order batch.

---

## Production measurements (2026-08-07)

### Volume (last 12h)

| Metric | Value |
|--------|------:|
| `service_reference.assigned` | **598** |
| `transaction.assigned` | **598** |
| `service_reference.driver_guide_sent` | **597** |
| Pending jobs (probe) | 0 |

### Peak assign minutes (IST)

| Minute | Assigns | Span (s) | Actors | Guides next 15m |
|--------|--------:|---------:|-------:|----------------:|
| 09:43 | 42 | 30 | 2 | 67 |
| 13:40 | 42 | 37 | 2 | 49 |
| 10:38 | 35 | 28 | 4 | 76 |
| 12:55 | 35 | 33 | 2 | 55 |
| 14:14 | 30 | 59 | 1 | 35 |

### Queue worker (DriverGuide)

Continuous serial drain example (14:14–14:17 IST): DONE every ~5–6s, occasional **8s**. Matches ~30 assigns in that window.

Note: `bulk_assign.batch.finished` `duration_ms` info logs were **not present** in current `laravel.log` (only `bulk_assign.order.failed` ERROR lines). HTTP wall estimated from audit timestamp spans in peak minutes.

### Automation

Recent reconcile log:

```
Mode: reconcile
Elapsed: 4945.9ms
Dirty slices: health, validation, recent_events
```

Those dirty slices are exactly what `markCaseOrOrderChanged()` sets on each case close inside Assign Reference.

---

## Sync call chain (batch)

```
DashboardWorkspaceActionController::batchTransaction
  → WorkspaceBatchTransactionActionService::assign
    → OrderTransactionService::assignTransactionIdToIncidents
         FOR each distinct order:
           assignTransactionId(..., broadcast: false)
             → assertCommercialAllowsServiceReference
             → CustomerVerificationService::assertCanCompleteService
             → assertNoActiveBusinessHoldOnOrder
             → DB::transaction {
                  order update
                  audit service_reference.assigned
                  TeamMemberActivityService::recordCaseAction
                  schedule SendServiceReferenceDriverGuideJob (afterCommit)
                  audit transaction.assigned
                  closeActiveServiceCasesForOrder(broadcast: false)
                }
             → afterCommit: notifyTransactionCompleted + flushKpiCoalesce
         render service-case-row × selected incidents
         DashboardBroadcastService::transactionsAssigned (once)
    → WorkspaceRefreshRenderer::buildBatchRefreshPayload
         liveReverbMetricsFor (KPIs)
         adminReadyQueueRemoveRowsForIncidents
```

### Case close (per open incident on order)

```
ServiceCaseStatusService::updateStatus(Closed)
  → nested DB::transaction
  → audit service_case.status_changed
  → DashboardSnapshotStore::forget()
  → AutomationOperationsSnapshotInvalidator::markCaseOrOrderChanged()
  → IncidentWaitingStateService::clearActiveIfPresent
  → completeScheduledSupportAppointments
```

---

## Events / broadcasts / notifications

| Kind | Name | When |
|------|------|------|
| Audit (not Laravel event) | `service_reference.assigned` | Sync in txn |
| Audit | `transaction.assigned` | Sync in txn |
| Audit | `service_case.status_changed` | Sync per close |
| Audit | `service_reference.driver_guide_sent` | Async in job |
| Notification | `TransactionCompletedNotification` | afterCommit, database channel |
| Reverb | `NotificationCreated` | via `BroadcastNotificationCreated` listener |
| Reverb | `ReferenceNumbersUpdated` | after batch/single assign (hybrid realtime) |
| Job | `SendServiceReferenceDriverGuideJob` | afterCommit per order |

No `Event::dispatch` domain event for assignment.

---

## Explicitly not in path

- Customer360 invalidate/rebuild  
- RadiumBox order enrichment sync  
- Bonvoice / incoming-call assist  
- `ServiceCaseAutomationMonitorService` / `ServiceCaseAutomationGraceService`  
- Deferred smart assignment  
- Laravel domain events for assignment  

(`RadiumBoxOrderEnrichmentJob` appears in queue DONE lines from **other** traffic; it is not dispatched by Assign Reference.)

---

## Key files

| Concern | Path |
|---------|------|
| Core assign | `app/Services/OrderTransactionService.php` |
| Batch workspace | `app/Services/WorkspaceBatchTransactionActionService.php` |
| Case close / invalidation | `app/Services/ServiceCaseStatusService.php` |
| Broadcast | `app/Services/DashboardBroadcastService.php` |
| Driver guide job | `app/Jobs/SendServiceReferenceDriverGuideJob.php` |
| Driver guide send | `app/Services/CommunicationActions/ReferenceNumberCommunicationService.php` |
| Automation dirty | `app/Services/Automation/AutomationOperationsSnapshotInvalidator.php` |
| Batch KPI/rows | `app/Services/WorkspaceRefreshRenderer.php` |
| Client fan-out | `resources/js/live-dashboard-reverb.js` |

---

## Phase 9 — Before / after (35-order batch)

| Metric | Before | After (Phase 9) |
|--------|-------:|----------------:|
| DriverGuide queue jobs | 35 | **1** (`SendServiceReferenceDriverGuideBatchJob`) |
| Snapshot forgets (closes) | ≈35+ | **1** (+ ≤1 broadcast) |
| Automation dirty marks | ≈35+ | **1** |
| Notification flush passes | 35 afterCommit | **1** (same DB notification count) |
| HTTP responses | 1 | **1** |
| Audits / commercial / close | — | **preserved** |

Production HTTP wall remeasure pending deploy. Local: `AssignReferencePhase9PerformanceTest` (8 orders) — coalesce asserts + &lt;15s HTTP budget.

### Rollback

Revert Phase 9 files listed in [inventory Phase 9](./p0-production-cpu-request-inventory.md#phase-9--batch-assign-reference-coalescing-implemented). No migrations. Drain in-flight batch DriverGuide jobs if removing the job class.

---

## Scope note

Investigation originally read-only; Phase 9 optimizations applied on the batch path only. Background host consumers (`platform:snapshots:warm`, `/dashboard/live`) remain outside this action’s exclusive attribution.
