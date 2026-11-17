# P-17-28 — Single dashboard inline reference assign fast path

## Problem

P-17-25/26 deferred expensive dashboard refresh, admin notifications, and customer driver-guide dispatch for **workspace batch** assign only. **Dashboard inline** assign (`POST orders/{order}/transaction` with `incident_id`) still:

- Rendered `service-case-row` Blade HTML and full KPI strip on the HTTP thread.
- Ran `flushKpiCoalesce()` (live Reverb metrics) synchronously in `DB::afterCommit`.
- Dispatched per-order driver-guide work on the hot path (queue async, but admin/KPI work blocked the response).

Production evidence (RD35299, 2026-11-17 ~14:54 IST): statutory mint logs at second granularity; driver-guide channel dispatch ~5.3s on workers; no `bulk_assign.*` lines — confirming **single inline** path, not batch.

## Change

When JSON assign includes `incident_id` (dashboard inline editor):

1. `OrderTransactionService::assignTransactionIdWithDeferredDashboardFollowUp()` activates the batch coalescer for one order, commits assignment + case closure, then enqueues the existing `assign_reference.batch_follow_up` outbox + `ProcessAssignReferenceBatchFollowUpJob` (same durable recovery as P-17-25).
2. `OrderTransactionController` omits `row_html` / `kpi_strip_html` and returns `extensions.operational_follow_up` with `customer_*` still **pending**.
3. `dashboard.js` calls `scheduleHybridKpiReconcile` when operational follow-up is present.

Order workspace / non-dashboard JSON (no `incident_id`) keeps the prior synchronous HTML response.

## Not changed

- Transactional assign, statutory issuance attempts, audits, commercial gates.
- Customer WhatsApp/email semantics (still via communication actions + outbox recovery).
- Batch workspace path (already fast).

## Pre-deploy

Part of next patch release after review; **no deploy** in P-17-28.