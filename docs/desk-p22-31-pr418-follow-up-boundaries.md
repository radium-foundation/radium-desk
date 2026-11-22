# P-22-31 — PR #418 hardening boundaries

## In scope on PR #418

- Schema, durable cancellation marker, ingestion guards, eligibility, service-layer submit/finance/refund link.
- Synchronous `PreShipmentCancellationIncidentService::retryClose`.
- Feature flag `PRE_SHIPMENT_CANCELLATION_ENABLED` default **false**.
- MariaDB migration rehearsal CI + InnoDB concurrency + gap-path regressions (P-22-31).

## Deferred to P-22-32 (explicit — not in PR #418)

| Item | Notes |
|------|--------|
| Operator HTTP UI and routes | Workspace/Finance surfaces for queue, review, approve, reject. |
| Async Incident retry queue job | e.g. `preship-cancel-close-incident` outbox worker; **retryClose** remains synchronous fallback. |

Owner merge of PR #418 does **not** authorize P-22-32 work without a separate task.