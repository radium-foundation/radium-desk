# Hardware Ably + Shiprocket track sync (P-07-09-290)

**Prompt ID:** RadiumDesk-P-07-09-290  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Base:** `origin/main` @ `c738d635` (P-07-09-289 investigation)

## Scope

- Hardware Dashboard realtime via existing Ably / Echo / `private-dashboard.{userId}`.
- Read-only Shiprocket tracking ingest (`trackByAwb` / `trackByShipment`).
- Persist provider track status; map only verified strings (`12` / pickup-queue phrases, `in_transit`).
- Verified `in_transit` overrides stale Desk **Ready for Pickup**.

## Non-goals (honored)

No dashboard/menu rename, inventory terminology change, payment/IRN/Cashfree/RBP94, new realtime stack, Shiprocket create/cancel/pickup/label/manifest, destructive DB, other websites.

## Architecture

1. Successful Hardware Fulfilment POST mutations call `DashboardBroadcastService::hardwareFulfilmentUpdated()` after commit (`mutationResponse`).
2. Event `HardwareFulfilmentsUpdated` on existing dashboard private channel (no HTML; no extra hardware channel).
3. Actor JSON includes `hardware_live` from `GET /dashboard/live/hardware`.
4. Client `hardware-dashboard-live.js` patches row + counts. `data-live-updates-enabled` stays `0` on Hardware (service-case merge remains off); Echo still connects and listens for hardware events.
5. Scheduler `shipping:sync-shiprocket-tracking --limit=25` every 5 minutes when shipping HTTP Shiprocket is enabled.

## Status mapping (verified only)

| Provider | Normalized | Dashboard |
|----------|------------|-----------|
| `12`, pickup queue phrases | `pickup_queued` | Desk milestones unchanged |
| `in_transit` / `in transit` | `in_transit` | Stage In Transit, next action View, Pickup sub-tab |
| anything else | `unknown` | Persist raw; do not invent stage |

## Safety

Shiprocket: GET track only. Timeout/empty/retryable does not wipe prior `provider_track_*`. Idempotent if status unchanged (no extra `shipment_events`, no Ably).
