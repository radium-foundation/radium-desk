# P-17-25 — Reliable, fast batch reference assignment

## Summary

Batch workspace assign (`POST /dashboard/workspace/batch-transaction`) now returns after DB commits and lightweight classification, while communications and KPI refresh run through a durable outbox + queue follow-up job.

## Design

1. **Unchanged:** per-order validation, authorization, transactional assign, statutory issuer, driver-guide idempotency (`ReferenceNumberCommunicationService`), single-order assign path.
2. **Removed from HTTP critical path:** N× Blade row HTML, sync KPI HTML (`liveReverbMetricsFor`), sync admin `TransactionCompletedNotification` fan-out, sync driver-guide batch dispatch.
3. **Still synchronous on HTTP:** cache/automation invalidation, outcome classification, `transactionsAssigned` Ably fan-out (lightweight).
4. **Durable follow-up:** `outbox_events` row (`assign_reference.batch_follow_up`) + `ProcessAssignReferenceBatchFollowUpJob` + `OutboxProcessorService` recovery. Follow-up performs admin notifications, chunked driver-guide jobs (WA/email via existing communication actions), and coalesced KPI broadcast.
5. **Admin JSON:** `extensions.operational_follow_up` reports assignment committed with per-channel **pending** (not delivered).

## Observability

Structured logs: `bulk_assign.batch.phase`, `bulk_assign.batch.follow_up.*`, extended `bulk_assign.batch.finished` with `follow_up_outbox_event_id`.