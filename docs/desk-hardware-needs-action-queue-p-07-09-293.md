# Hardware Needs Action default queue (P-07-09-293)

**Prompt ID:** RadiumDesk-P-07-09-293  
**Date:** 2026-09-15  
**Type:** Presentation filtering + selective inspect. No fulfilment state-machine change. **Not deployed.**

## Ledger / git

| Item | Value |
|------|--------|
| Ledger next ID | RadiumDesk-P-07-09-293 (after P-07-09-292) |
| Repo | `github.com/radium-foundation/radium-desk` |
| Branch | `cursor/hardware-needs-action-queue-ade8` |
| Base | `e748b454` (`cursor/dashboard-p1-perf-port-ade8` / P-292 docs) |

## Queue mapping (verified production classifier)

Default query: `hw_scope=active` (implied), `hw_filter=needs_action`.

| Nav | Filter | Classifier rule |
|-----|--------|-----------------|
| Needs Action | `needs_action` | mapping required **or** stage `awaiting_serial` **or** `awb_pending` **or** package photo pending |
| Product Mapping Required | `mapping_required` | status `Product mapping required`, or RIN support row without a fulfilment |
| Awaiting Serial | `awaiting_serial` | `HardwareFulfilmentOperationalStage::AwaitingSerial` |
| AWB Pending | `awb_pending` | `AwbPending` |
| Package Photo Pending | `package_photo_pending` | next action `Upload Package Photo` or status `Package photo pending` (including shipped/completed stage still awaiting evidence) |
| Shipping | `shipping` | Label/pickup/ready/out-for-pickup/picked-up/in-transit, excluding package-photo-pending |
| Out for Pickup | `out_for_pickup` | stage from `ShiprocketTrackNormalized::OutForPickup` |
| Ready for Pickup | `ready_for_pickup` | stage Ready for Pickup, not photo-pending |
| In Transit | `in_transit` | stage In Transit |
| Picked Up | `picked_up` | stage Picked Up (`ShiprocketTrackNormalized::PickedUp`) |
| Completed / Delivered | `completed` / `delivered` | `Delivered` or `Completed` stages, excluding package-photo-pending |
| All | `all` | existing Active work-queue rows (not default) |
| Legacy | `ready` / `exceptions` / `pickup` / `scheduled` / `hw_queue` | preserved |

**Not listed:** Out for Delivery — not a `ShiprocketTrackNormalized` value.

Shiprocket mappings in `ShiprocketTrackNormalized` were **not** changed: `pickup_queued`, `out_for_pickup`, `picked_up`, `in_transit`, `delivered`, `unknown`.

## Count / row implementation

- **Hardware chip / Active count:** SQL `hardware_fulfilments.state NOT IN (shipped, synced)` + awaiting work-candidate count + windowed RIN count. Does not inspect eligibility.
- **Queue counts:** counted from the selective inspect set (non-`ingested` fulfilments + awaiting + RIN). Full matching set, not the current page.
- **Rows:** filtered to the selected queue, then paginated (`hw_page`, 40 per page). Blade renders only that page.
- **Selective inspect:** `state != ingested`. Ingested majority is skipped. Opening **All** / Ready / Exceptions / Pickup / Scheduled still uses `allRows()` (full inspect) — remaining cost if operators open All.

Search (`q`) matches across the Active selective/all set so order-id search still finds rows outside Needs Action.

## Realtime

- Preserved `data-live-updates-enabled="0"` on Hardware (no service-case poller).
- Existing Echo init still runs when Echo is configured.
- `GET /dashboard/live/hardware` patches current `hw_scope`/`hw_filter`.
- `live-dashboard-reverb.js` listens for `.HardwareFulfilmentsUpdated` only when `[data-hardware-workspace]` is present. No new Ably topology. PHP event class was not invented on git-main.

## Production overlay

Copied/preserved overlay types: workspace filter/scope enums, Shiprocket track enum, operational stages (Out for Pickup / Picked Up / In Transit / Delivered), classifier track override, WorkQueue Active/Shipped chip, workspace/row blades, live service.

**Not deployed.** No `rsync --delete`. No `deploy-kvm.sh`.

## Tests

- `HardwareDashboardNeedsActionQueueTest`
- `DashboardHardwareSsrPerformanceTest`
- `HardwareFulfilmentDashboardNavigationTest`
- `HardwareFulfilmentWorkQueueTest`
- `HardwareFulfilmentOperationalClassifierTest`

## Performance

Production 936-row Hardware SSR (P-292, not re-run this gate): wall 3637 ms, PHP 3230 ms, HTML 2.12 MB, 936 rows, inspect ~938.

This gate: **NO** production re-measure (deploy is a separate gate).

Local PHPUnit (SQLite): 2 ingested + 1 awaiting-serial + 1 invoice-issued → default inspect **2** fulfilments, **1** Needs Action row; All inspect **4** and renders 4. 45 awaiting-serial rows paginate 40 + 5. Chip still counts full Active set.
