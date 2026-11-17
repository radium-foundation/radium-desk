# P-17-26 — Reference assignment reliability & pre-deploy gate

## Outbox completion semantics

`assign_reference.batch_follow_up` **Completed** means `operational_dispatches_enqueued`:

- Admin `TransactionCompletedNotification` database rows created.
- `SendServiceReferenceDriverGuideBatchJob` chunk(s) queued.
- Coalesced `DashboardKpisUpdated` broadcast fired when applicable.

It does **not** mean customer WhatsApp/email were delivered. Those remain on communication-action audits, notification dispatch results and driver-guide job retries (existing lifecycle).

## Phased idempotency

Follow-up processor persists `payload.phases` after each phase so retries skip completed work (admin notifications are not duplicated when driver-guide dispatch fails mid-flight).

## Dashboard fallback

Batch workspace responses with `refresh.kpis === false` schedule `scheduleHybridKpiReconcile` after success so KPIs refresh even without synchronous `kpis_html`.

## Customer channels

Driver installation guide automation uses `CommunicationActionExecutorService` with per-channel results; WhatsApp and email are resolved independently in `allowedChannels`.