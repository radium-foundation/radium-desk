# P-07-09-298 Shiprocket production progression

**Prompt ID:** RadiumDesk-P-07-09-298  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Branch:** `cursor/shiprocket-production-progression-5359`  
**Mapping source:** `cursor/shiprocket-tracking-progression-5359` @ `cc8d1bafc34cdcdbdc7c77b50187d6ebd7128251`  
**Production overlay identity preserved:** `b13df4e7b6291658224ac28fe92dfbe3f81dca47`

## Verdict

**SUCCESS.** Mapping/classifier files were overlaid onto current production without replacing production-only routes or CSS/JS. Tracking was then enabled via `.env`. Live GET `"19"` + `current_status` `Out for Pickup` persists as `out_for_pickup` and the Hardware dashboard shows **Out for Pickup / View / Pickup / In Progress**. Desk `hardware_fulfilments.state` was not written to `shipped`.

## Pre-change identity

| Item | Result |
|------|--------|
| Ledger last ID | RadiumDesk-P-07-09-297 |
| Next unused ID | RadiumDesk-P-07-09-298 |
| `origin/main` | `c738d635` (no tracking stack) |
| Production host | `ravi@187.127.129.16:/var/www/radium-desk` (no git checkout) |
| Overlay vs `b13df4e7` | 38/40 compared application files identical |
| Production-only diffs vs branch | `routes/web.php`, `resources/views/dashboard/partials/recent-service-cases.blade.php` |
| Tracking env | `SHIPROCKET_TRACKING_SYNC_ENABLED` **absent** → config **false** |
| HTTP Shiprocket | `SHIPROCKET_HTTP_ENABLED` present, gateway `HttpShiprocketGateway` |
| Migration | `2026_09_15_100000_add_shiprocket_track_columns_to_shipments` already **Ran** (batch 23) |
| Shipments fingerprint | 64 rows, id-sum **2080** |
| Tracked rows | 0 |

Production-only overlays confirmed present before write: purchasing routes, historical-orders routes, C360 routes, `hw_scope` / `hw_filter` nav, B2B badge, Date column, Hardware live URL, CSS/JS hashes matching `b13df4e7`.

## Mapping overlay (tracking still OFF)

Backup: `/var/backups/radium-desk/overlays/p-07-09-298-20260915T163726Z`  
Dump SHA-256: `3b215e8c1864b9f2f22921e1db6c70cf0b72e4b7f6a4d190cd5b133d1d9369e8`

Mechanism: individual `sudo install -m 644 -o ravi -g ravi`. Not `deploy-kvm.sh`. Not `rsync --delete`. `routes/web.php` and `recent-service-cases.blade.php` were **not** replaced.

Files overlaid (hashes match `cc8d1baf`):

| File | Post-overlay SHA-256 |
|------|----------------------|
| `app/Enums/HardwareDashboardQueue.php` | `fb49a2fa…` |
| `app/Enums/HardwareFulfilmentOperationalStage.php` | `084fec04…` |
| `app/Enums/ShiprocketTrackNormalized.php` | `b62b24e9…` |
| `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalClassifier.php` | `8d5295fd…` |
| `app/Services/HardwareFulfilment/Data/HardwareFulfilmentStepper.php` | `423d4846…` |
| `app/Services/HardwareFulfilment/HardwareShiprocketTrackingService.php` | `3a080b3e…` |
| `app/Services/Shipping/ShiprocketTrackingNormalizer.php` | `3fe685ec…` |

Then `artisan optimize:clear` + `optimize`. Command still printed `Shiprocket tracking sync disabled.` In-process normalizer: `19` → `out_for_pickup`, `42` → `picked_up`, `7` → `unknown` (numeric 7 is not mapped).

Auth kernel after mapping overlay (tracking still off): Hardware pickup 200 with `hw_scope` / `hw_filter` / B2B / Date / purchasing / historical / C360; live hardware JSON keys `rows,remove_fulfilment_ids,scope_counts,filter_counts,hardware_count`; counts active 936 / shipped 2 / pickup 57; purchasing 200; inventory Hardware Fulfilments 200 with In Progress + Ready for Pickup chips.

## Activation gates (all pass)

| Gate | Evidence |
|------|----------|
| Command off when flag false | Production `shipping:sync-shiprocket-tracking --limit=1` → `Shiprocket tracking sync disabled.` |
| GET-only provider access | `HttpShiprocketGateway::trackByAwb` / `trackByShipment` call `send('get', …)` only |
| Failures preserve previous status | Timeout/empty/retryable return `skipped` without wiping `provider_track_*` (tests + service) |
| Bounded polling | `--limit=25`, `min_interval_seconds=300` |
| No provider mutation | Track path does not create/cancel/pickup/label/manifest |
| Duplicate execution | Scheduler `withoutOverlapping`; `lockForUpdate` per shipment |
| Scheduler | `2-59/5`, `when(sync_enabled && shipping.enabled && provider=shiprocket && http_enabled)` |
| `HardwareFulfilmentsUpdated` | Published only when persisted raw/normalized status changes |

Shiprocket credential keys were not modified.

## Tracking enable

Appended `SHIPROCKET_TRACKING_SYNC_ENABLED=true` to production `.env` (key was absent). Recached. `config('shipping.tracking.sync_enabled')` = **true**.

Bounded runs:

- Manual `--limit=25` → `scanned=25 changed=25 skipped=0 failed=0`
- Targeted GET ingest of remaining Ready-for-Pickup shipment 1
- Second `--limit=25` → `scanned=25 changed=22 skipped=3 failed=0`
- Scheduler also recorded `scanned=25 changed=12 skipped=13 failed=0` (mutex/min-interval skips)

A one-line cURL resolve timeout appeared on the first manual run; `failed=0` and 25 rows still persisted. No rollback was required.

## Production path (status 19)

Live GET `trackByAwb` for shipment 49 / HF 80 / **RBP102**:

- Raw `tracking_data.shipment_status` = `"19"`
- `current_status` = `Out for Pickup`
- Persisted `provider_track_status=19`, `provider_track_normalized=out_for_pickup`

Same persisted pair on HF 37 / RDE318360 and HF 78 / RBP98.

`GET /dashboard/live/hardware?ids[]=80,37,78&hw_scope=active&hw_filter=pickup`:

| Source | Dashboard status | Next action | Filter | Queue | HTML |
|--------|------------------|-------------|--------|-------|------|
| RBP102 | Out for Pickup | View | pickup | pickup | Out for Pickup present; Ready for Pickup absent |
| RDE318360 | Out for Pickup | View | pickup | pickup | same |
| RBP98 | Out for Pickup | View | pickup | pickup | same |

Classifier / Inventory section for RBP102: **In Progress**.

`hardware_fulfilments.state` for these rows remains `awb_assigned`. Shipped fulfilment count stayed **3**. Shipments fingerprint stayed **64 / 2080**.

## Other live mappings (not invented)

| Raw | Normalized | Notes |
|------|------------|-------|
| `19` | `out_for_pickup` | 3 rows |
| `42` | `picked_up` | 6 rows |
| `in_transit` text / activity | `in_transit` | including raw `18` only when activity text mapped |
| `delivered` text | `delivered` | raw `7` is **not** mapped; activity `Delivered` mapped RDE318434 (previously GET 19) |
| `12` / pickup queue | `pickup_queued` | unchanged |
| `0`,`3`,`6`,`18`,`21`,`38` without verified text | `unknown` | do not override Ready for Pickup |

## Not performed

- `deploy-kvm.sh` / `rsync --delete`
- Wholesale `routes/web.php` replace
- Changing Shiprocket credentials
- Auto `hardware_fulfilments.state = shipped`
- Deleting shipment tracking data
- Browser owner two-tab Ably E2E
- Pending UPI migrations

## Rollback (not used)

Unset/disable `SHIPROCKET_TRACKING_SYNC_ENABLED` in `.env`, `optimize:clear` + `optimize`. Restore the seven files from `p-07-09-298-20260915T163726Z` if the overlay must be reverted. Do not drop `provider_track_*` columns or delete tracking rows.
