# P-07-09-297 Shiprocket tracking progression (status 19)

**Prompt ID:** RadiumDesk-P-07-09-297  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Branch:** `cursor/shiprocket-tracking-progression-5359`  
**Base:** `origin/cursor/hw-ably-shiprocket-sync-c269` @ `11619890` (production overlay identity `b13df4e7` plus P-295/P-296 confirmation docs)

## Pre-change

| Item | Value | Class |
|------|-------|-------|
| Ledger last ID | RadiumDesk-P-07-09-296 | VERIFIED |
| Next unused ID | RadiumDesk-P-07-09-297 | VERIFIED |
| `origin/main` | `c738d635` (no tracking stack) | VERIFIED |
| Tracking source of truth | `cursor/hw-ably-shiprocket-sync-c269` | VERIFIED |
| Production overlay | P-07-09-295 named-file overlay, identity `b13df4e7` | VERIFIED (P-296 40/40 hash match) |
| `SHIPROCKET_TRACKING_SYNC_ENABLED` | OFF / absent; config default false | VERIFIED |
| Live Shiprocket GET | P-290 parsed `tracking_data.shipment_status` `"19"`, activity `Out for Pickup` | VERIFIED (prior read-only GET; not repeated) |

No credentials were printed. No Shiprocket or production shipment mutation.

## Path (why “Ready for Pickup” stays)

```
Shiprocket GET /courier/track/awb/{awb}
  → HttpShiprocketGateway: tracking_data.shipment_status (numeric string)
  → HardwareShiprocketTrackingService persist provider_track_status + provider_track_normalized
  → HardwareShipmentEligibility inspect()
  → HardwareFulfilmentOperationalClassifier
  → dashboard label / next action / hw_filter counts
```

| Layer | Before this prompt | After this prompt |
|-------|--------------------|-------------------|
| Raw provider | `"19"` + activity Out for Pickup | unchanged parse |
| Normalized | `unknown` (19 not mapped) | `out_for_pickup` |
| Persisted shipment | raw 19 + unknown, only if tracking ON | same persist rules; 19 → `out_for_pickup` |
| Fulfilment `state` / `ready_for_pickup_at` | unchanged Desk operator milestone | still not overwritten |
| Classifier | `readyForPickup` wins because `overridesReadyForPickup()` was InTransit-only | Out for Pickup / Picked Up / In Transit / Delivered override |
| Label | Ready for Pickup | Out for Pickup (or later verified label) |
| Next action | Ready (non-mutating) | View |
| Dashboard filter | Pickup (label still Ready for Pickup) | Pickup with provider label; not Ready |
| Inventory section | Ready for Pickup (`ReadyForPickup` stage) | In Progress |

Root cause is **two stacked facts**, both verified:

1. **Mapping:** status `19` was unknown, so stale `ready_for_pickup_at` kept the Ready for Pickup label.
2. **Ingest:** tracking sync is still OFF, so production rows still have null `provider_track_*` until a future activation gate.

Do not claim production display is fixed until tracking ingest has run against live AWBs.

## Verified mappings implemented

| Raw (`tracking_data.shipment_status` or activity text) | Normalized | Dashboard label | Overrides Ready for Pickup | Evidence |
|----------------------------------------------------------|------------|-----------------|----------------------------|----------|
| `12`, pickup-queue phrases | `pickup_queued` | Pickup queued | NO | P-07-09-88 / existing overlay |
| `19`, `Out for Pickup`, `out_for_pickup`, `ofp` | `out_for_pickup` | Out for Pickup | YES | P-290 production GET + official Shiprocket term OFP |
| `42`, `PICKED UP` | `picked_up` | Picked Up | YES | Official Shiprocket Postman track example (`shipment_status: 42`, `current_status: PICKED UP`) |
| `in_transit`, `In Transit` | `in_transit` | In Transit | YES | Existing overlay mapping |
| `delivered` (text only) | `delivered` | Delivered | YES | Official Shiprocket term; **no numeric code mapped** |

Not mapped (not invented): `7`, `18`, OFD numeric, RTO codes. Unknown raw status is persisted as `unknown` and does **not** override Ready for Pickup.

If raw numeric is unknown, ingest may use verified `current_status` / `activity` from the same GET. Raw status still wins when it is already mapped (so Fake `in_transit` is not replaced by activity “Picked up”).

## Safety (unchanged)

- GET track only. No create / pickup / cancel / label / manifest.
- Timeout / empty / retryable skips without wiping prior `provider_track_*`.
- Unchanged status: timestamp only; no extra `shipment_events`; no `HardwareFulfilmentsUpdated`.
- Scheduler `withoutOverlapping`; min interval 300s; `--limit=25`.
- `SHIPROCKET_TRACKING_SYNC_ENABLED` remains default **false**.

## Tracking activation

**SEPARATE DEPLOYMENT GATE — not performed.**

Overlaying this mapping without enabling sync will not change live labels. Enabling requires:

1. This mapping + tests on the live overlay.
2. Explicit `SHIPROCKET_TRACKING_SYNC_ENABLED=true` (do not rely on a missing key).
3. Confirm HTTP Shiprocket remains GET-only.
4. Bounded first run (`--limit=25`) and log review.
5. Rollback is unset the env key (command no-ops).

## Realtime

`HardwareFulfilmentsUpdated` is still published only when persisted raw/normalized status changes. Ably is not redesigned.

## Not performed

- Production deploy / named-file overlay
- Enabling tracking
- Live Shiprocket GET (not repeated)
- `deploy-kvm.sh` / `rsync --delete`
- Fulfilment `state` mutation / auto `markShipped`
