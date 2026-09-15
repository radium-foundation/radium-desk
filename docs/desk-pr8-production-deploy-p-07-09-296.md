# PR #8 production Hardware realtime deploy confirmation (P-07-09-296)

**Prompt ID:** RadiumDesk-P-07-09-296  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**PR:** https://github.com/radium-foundation/radium-desk/pull/8  
**Branch:** `cursor/hw-ably-shiprocket-sync-c269`  
**Intended / production overlay SHA:** `b13df4e7b6291658224ac28fe92dfbe3f81dca47`

## Verdict

**SUCCESS — already deployed.** P-07-09-295 named-file surgical overlay is live on KVM `/var/www/radium-desk`. This gate created a new timestamped backup, compared the approved file set to `b13df4e7`, found **40/40 hashes identical**, and **did not re-overlay** application files. Tracking remains **OFF**. No `deploy-kvm.sh`. No `rsync --delete`. No wholesale `routes/web.php` replace. No production `.env` change. No migration rerun.

Browser Ably E2E was **not** performed. Owner two-tab test remains the realtime proof.

## Ledger

Next unused after `RadiumDesk-P-07-09-295` was **`RadiumDesk-P-07-09-296`**.

## Pre-deploy

| Item | Result |
|------|--------|
| Repo | `/agent/repos/radium-desk` isolated from other workspace repos |
| Branch / HEAD (pre-docs) | `cursor/hw-ably-shiprocket-sync-c269` @ `7bf10f9b` (docs-only after `b13df4e7`) |
| Worktree | CLEAN before this gate’s docs |
| Remote | `origin` → `github.com/radium-foundation/radium-desk` |
| Production overlay | `b13df4e7b6291658224ac28fe92dfbe3f81dca47` |
| Mechanism | Named-file `install -m 644` as used in P-07-09-295; not used this gate because hashes already matched |

## Backup (this gate)

`/var/backups/radium-desk/overlays/p-07-09-296-20260915T150600Z`

- Approved application files + `routes/web.php` + `manifest.json` + `dashboard-Bh9c8t1x.js`
- `shipments` + `migrations` mysqldump gzip SHA-256 `59869db34853933b239f1e2219cf7d431da006aa919635478511fabbfc8849d2`

Rollback: restore those copies with `sudo install -m 644` (do not `rsync --delete`). Do not drop tracking columns unless rolling back the schema from P-07-09-295.

## Database

| Item | Result |
|------|--------|
| `2026_09_15_100000_add_shiprocket_track_columns_to_shipments` | Already **Ran** (batch 23). **Not rerun.** |
| Columns | `provider_track_status`, `provider_track_normalized`, `provider_tracked_at` present |
| Shipments fingerprint | 64 rows, id-sum **2080** (unchanged vs P-07-09-295) |
| Unrelated pending | 3 UPI migrations still **Pending** |

## Tracking / Ably

`SHIPROCKET_TRACKING_SYNC_ENABLED` **absent**. `config('shipping.tracking.sync_enabled')` = **false**. Ably key set. Broadcast default `ably`. No `.env` edit.

## Post-check (no Hardware mutation)

| Check | Result |
|------|--------|
| `/up` | 200 |
| `/login` | 200 |
| Unauth Hardware Dashboard / live endpoint | 302 to login (expected) |
| Auth kernel Hardware Dashboard `hw_scope` active/shipped | 200 |
| Auth kernel `hw_filter` all/ready/exceptions/pickup/scheduled | 200 |
| `GET /dashboard/live/hardware` | 200; keys `rows`, `remove_fulfilment_ids`, `scope_counts`, `filter_counts`, `hardware_count` |
| Counts snapshot | active 936, shipped 2; ready 8, exceptions 871, pickup 57, scheduled 0 |
| Bundle `dashboard-Bh9c8t1x.js` | Contains `HardwareFulfilmentsUpdated`, `hw_scope`, historical-order-summary |
| Purchasing / Historical Orders / C360 | Present in authenticated Hardware HTML |
| B2B + Date column | Present (`B2B` badge markup, Date header/cells) |
| Hardware product/variant cell | Present (`dashboard-hardware-product` / `productDisplay()`) |
| Production-only routes | `routes/web.php` still contains purchasing, historical, c360 (not replaced) |
| New deploy errors | None from this gate (no app file write). Pre-existing `UnhandledMatchError` in `AutomationOperationsValidationCollector` continues. |

`data-live-updates-enabled="0"` remains on the Hardware workspace (same as P-07-09-295). Echo still initialises when that flag is off.

## Owner handoff (required for realtime E2E)

Production is ready for the owner’s authenticated two-tab test:

1. **TAB A:** Open Hardware Dashboard (`hw_scope` / `hw_filter` as needed). Leave it open. Do not refresh.
2. **TAB B:** Perform one normal Hardware operation the owner is already comfortable performing on a real record.
3. **TAB A:** Confirm the affected row and counts update without refresh, no duplicate row, no JS error.

This gate does **not** claim browser realtime success.

## What this gate did not do

- Re-copy identical production files
- `deploy-kvm.sh` / `rsync --delete`
- Enable tracking, change Ably, DNS, Cloudflare, OLS, secrets
- Run pending UPI migrations
- Production Hardware mutation
- Browser two-tab Ably E2E
