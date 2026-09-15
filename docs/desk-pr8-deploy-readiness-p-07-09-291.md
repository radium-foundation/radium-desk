# PR #8 deployment readiness (P-07-09-291)

**Prompt ID:** RadiumDesk-P-07-09-291  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**PR:** https://github.com/radium-foundation/radium-desk/pull/8  
**Branch:** `cursor/hw-ably-shiprocket-sync-c269`  
**HEAD (unchanged from P-07-09-290):** `c7dca10807b35e4c83443eaa65c6e33d62bb349a`  
**Base / `origin/main`:** `c738d6355bfd6793e663ef0b3cdea93dfecf0999`

## Verdict

**STOP — production deploy not performed.**

The implementation remains additive and scoped. Local tests and local Hardware Dashboard visual QA passed. Production overlay of this branch is still unsafe: live Desk is a historical/live superset of `main`, and wholesale named-file overlay of `routes/web.php` / Hardware classifier / dashboard JS would drop live extras.

Do not claim Shiprocket → Desk persist → Ably → Dashboard consume as a complete production E2E chain.

## Step 1 — Repository state (this session)

| Fact | Value | Confidence |
|------|-------|------------|
| Path | `/agent/repos/radium-desk` | VERIFIED |
| Remote | `https://github.com/radium-foundation/radium-desk` | VERIFIED |
| Branch | `cursor/hw-ably-shiprocket-sync-c269` (clean vs origin) | VERIFIED |
| HEAD / PR head | `c7dca108` | VERIFIED |
| `origin/main` | `c738d635` | VERIFIED |
| Merge-base | `c738d635` | VERIFIED |
| PR commits | `8d0413a5`, `fca24db6`, `c7dca108` | VERIFIED |
| PR changed since P-290 | No | VERIFIED |
| Next ledger ID | RadiumDesk-P-07-09-291 | VERIFIED |

## Step 2 — Diff scope

Expected PR areas remain: `provider_track_*` migration, tracking sync job, GET `trackByAwb`/`trackByShipment`, conservative classifier, `HardwareFulfilmentsUpdated`, `DashboardBroadcastService`, `GET /dashboard/live/hardware`, `hardware-dashboard-live.js`, tests, scheduler in `bootstrap/app.php`.

No payment/Cashfree/IRN/financial files in the PR. No wholesale replacement of live-only routes in the **git** `routes/web.php` (one added `dashboard/live/hardware` line after `dashboard/live/rows`). No secrets committed.

## Step 3 — Migration

File: `database/migrations/2026_09_15_100000_add_shiprocket_track_columns_to_shipments.php`

Additive nullable `provider_track_status` (64), `provider_track_normalized` (32), `provider_tracked_at`. `down()` drops those three only.

**Production schema (P-290 SSH):** columns and migration file absent. **This session:** SSH to KVM `ravi@187.127.129.16` returned `Permission denied (publickey)` — production schema not re-queried here.

Do not run the production migration until a surgical overlay path exists.

## Step 4–5 — Shiprocket

PR uses existing GET `trackByAwb` / `trackByShipment` only. No create/pickup/cancel/label/manifest in the tracking service.

Config: `SHIPROCKET_TRACKING_SYNC_ENABLED` defaults **true** in `config/shipping.php`. Scheduler runs when that flag is true **and** shipping enabled **and** provider `shiprocket` **and** HTTP enabled.

**P-290 production env (do not silently flip):**

- `SHIPROCKET_ENABLED=true`
- `SHIPROCKET_PROVIDER=shiprocket`
- `SHIPROCKET_HTTP_ENABLED=true`
- `SHIPROCKET_TRACKING_SYNC_ENABLED=ABSENT` → code default would **enable** GET polling (`--limit=25`, every 5 minutes, `withoutOverlapping`) after a full PR deploy of `bootstrap/app.php` + config.

**Real Shiprocket tracking GET:** YES — performed in P-290 against existing production shipment 58 (AWB not printed). `ok=1`, parsed status `"19"`, activity `Out for Pickup`. Gateway reads `tracking_data.shipment_status` / `status` (numeric). **`"19"` is not mapped.** Persist-as-unknown is correct. Do not map OFD / Picked Up / Delivered without owner-defined Desk semantics.

This session did **not** repeat the live GET (no SSH).

## Step 6 — Classification

| Provider | Normalized | Dashboard |
|----------|------------|-----------|
| `12` / pickup-queue phrases | `pickup_queued` | Desk milestones unchanged |
| `in_transit` / `in transit` | `in_transit` | In Transit; overrides stale Ready for Pickup |
| anything else including `"19"` | `unknown` | Persist raw; do not invent a stage |

Delivered / OFD / Picked Up: **not supported**.

## Step 7 — Scheduler

`shipping:sync-shiprocket-tracking --limit=25` cron `2-59/5 * * * *`, `withoutOverlapping`, gated. Timeout/empty/retryable skip without wiping prior `provider_track_*`. Ably only when persisted status changes. Tests cover this.

## Step 8–9 — Ably / live patch

Existing Ably + Echo/Pusher + `DashboardBroadcastService` + `private-dashboard.{userId}`. Event `HardwareFulfilmentsUpdated`. Hardware tab HTML still `data-live-updates-enabled="0"` (verified local curl). Echo still listens for hardware events when live merge is off (`shouldInitEcho` includes `! liveUpdatesEnabled`).

Local live patch: CustomEvent `hardware-dashboard:updated` + `GET /dashboard/live/hardware` applied row/counts without reload. **Ably socket consume on production: NOT VERIFIED.** Local `.env` has no Echo key.

## Step 10 — Tests (this session)

- Pint dirty: passed
- Vite `npm run build`: passed (gitignored `public/build`)
- Vitest hardware-dashboard-live + live-dashboard-reverb + hardware-action-dialog: **56 passed**
- PHPUnit filter (normalizer, classifier, tracking sync, HttpShiprocketGateway `test_track*`, Hardware realtime, Hardware dashboard navigation): **54 passed**

## Step 11 — Visual QA

Local sqlite + `php artisan serve` at `http://127.0.0.1:8000`. Seeded disposable RDE902011 / RDE902040 / RIN902010. No business mutations.

Desktop: Hardware chip, Ready / Exceptions / Pickup / Completed, rows, Allocate Serial GET dialog (Cancel). Live count patch without reload.

390px / 412px: tabs usable; table status text clips (`Awai Ser` / truncated Blocked) — pre-existing compact table, not redesigned in this PR.

## Step 12–16 — Production deploy

**NO — Not performed.**

Unsafe because:

1. Live `routes/web.php` is a superset of `main` (historical-orders and other overlays). Wholesale replace forbidden.
2. Live Hardware classifier/workspace/dashboard JS diverge from `main` (B2B/date/shipped-scope/historical-order). Overlaying PR files would drop live extras or ship a Vite bundle without historical-order JS.
3. This session could not re-establish SSH, backup, or rollback on the KVM.

If a later gate deploys: named-file surgical overlay only; insert `dashboard/live/hardware` without replacing `web.php`; merge live Hardware overlay files; do not `deploy-kvm.sh --delete`; record that tracking sync would start GET polling unless `SHIPROCKET_TRACKING_SYNC_ENABLED` is set explicitly.

## Evidence distinction

| Link | Status |
|------|--------|
| Shiprocket GET shape (`"19"` / Out for Pickup) | VERIFIED (P-290 production GET) |
| Persist provider_track_* on production | UNKNOWN (not deployed) |
| Classifier In Transit override | VERIFIED (unit/feature tests); production UNKNOWN |
| HardwareFulfilmentsUpdated publish | VERIFIED (tests); production UNKNOWN |
| Dashboard consume via Ably | INFERRED from local CustomEvent + JS; production UNKNOWN |
| Full production E2E | UNKNOWN |

## Not performed

- Production deploy / migrate / overlay
- `deploy-kvm.sh --delete` / rsync `--delete`
- Wholesale `routes/web.php` replace
- Financial / payment / Cashfree / IRN changes
- Destructive DB
- Shiprocket mutations / shipment creation
- Mapping of `"19"` / Delivered / OFD / Picked Up
- PR merge
