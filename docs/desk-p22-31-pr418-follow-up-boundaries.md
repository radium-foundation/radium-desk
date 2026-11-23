# P-22-31 — PR #418 hardening boundaries

## In scope on PR #418

- Schema, durable cancellation marker, ingestion guards, eligibility, service-layer submit/finance/refund link.
- Synchronous `PreShipmentCancellationIncidentService::retryClose`.
- Feature flag `PRE_SHIPMENT_CANCELLATION_ENABLED` default **false**.
- MariaDB migration rehearsal CI + InnoDB concurrency + gap-path regressions (P-22-31).

## Delivered in P-23-01 (post–PR #418 merge)

| Item | Notes |
|------|--------|
| Operator HTTP UI and routes | Gated middleware + policy; order create/show; Finance central queue; approve/reject/link-refund. |
| Async Incident retry queue job | `ClosePreShipmentCancellationIncidentJob` on queue **`preship-cancel-close-incident`**; idempotency `psc-incident:{request_id}`. |

Feature flag default remains **`PRE_SHIPMENT_CANCELLATION_ENABLED=false`**. State C/D still excluded.