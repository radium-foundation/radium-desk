# P-11-55 — Legacy Ready / Pickup filter SQL pagination blocker

## Scope

Hardware dashboard filters `hw_filter=ready` and `hw_filter=pickup` (legacy deep links) still route through
`HardwareFulfilmentWorkQueue::legacyDashboard()`, which materializes the operational queue in memory before
slicing the requested page.

## What was improved in P-11-55

- `exceptions` and `scheduled` legacy filters now use SQL identifier pagination (`exceptionsPage`, `scheduledPage`).
- Legacy Ready/Pickup paths reuse cached SQL filter counts instead of recomputing counts from the full in-memory queue.

## Why Ready and Pickup remain in-memory

Classification depends on `HardwareFulfilmentOperationalClassifier` + `HardwareDashboardQueue::fromStage()`:

- **Ready** excludes `AwaitingFulfilment` but includes many post-ingest stages when `dashboardQueue()` resolves to `Ready`.
- **Pickup** matches `PickupManifestPending` and `ReadyForPickup` presentation stages, which are not identical to the
  modern `hw_filter=ready_for_pickup` SQL lifecycle tab.

Reproducing those rules purely in SQL without duplicating classifier drift risk requires either:

1. Persisted presentation stage columns, or
2. A shared SQL translation of the classifier (large, high-risk change).

Neither fits the minimal PR #163 scope without behavioral risk.

## Operator impact

- Modern navigation uses SQL-backed filters (`needs_action`, `ready_for_pickup`, shipping sub-filters, `all`, etc.).
- Legacy `ready` / `pickup` links are rare; when used, pagination is correct but the server still scans the queue to classify rows.

## Recommended follow-up

Introduce a verified SQL projection (materialized view or denormalized `hardware_fulfilment_presentation_queue`) updated
on ingest/shipment transitions, then port Ready/Pickup pagination to identifier slices.
