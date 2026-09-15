# PR #8 surgical overlay merge (P-07-09-292)

**Prompt ID:** RadiumDesk-P-07-09-292  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**PR:** https://github.com/radium-foundation/radium-desk/pull/8  
**Branch:** `cursor/hw-ably-shiprocket-sync-c269`

## Objective

Port PR #8 Shiprocket tracking + Hardware Dashboard Ably functionality while preserving verified live-production overlay behavior identified in P-07-09-291 production reconciliation.

**This gate:** implement / test / commit / push only. **No production deploy.**

## Verdict

**Implementation complete on branch.** Production deploy remains a separate gate.

## Surgical merge summary

| Area | Action |
|------|--------|
| `HardwareFulfilmentOperationalRow` | Replaced with live richer row (`lastActionDateIst`, B2B, timeline helpers, workspace filter matching) |
| `HardwareFulfilmentOperationalClassifier` | Live overlay + merged PR in-transit override via `ShiprocketTrackNormalized` |
| `HardwareShipmentEligibility` | Live overlay + PR `providerTrackStatus` / `providerTrackNormalized` fields |
| `HardwareFulfilmentActivityTimestamps` | Added from production overlay |
| `HardwareWorkspaceFilter` | Added from production overlay (row filter helpers) |
| `hardware-workspace*.blade.php` | Live Date column, B2B badge, `#dashboard-hardware-body`, colspan 8 |
| `dashboard.js` | Preserved PR hardware live listener + restored `initHistoricalOrderSummary` |
| `historical-order-summary.js` + modal | Added from production overlay |
| `index.blade.php` | Added historical modal include; kept `data-live-hardware-url` |
| `hardware-action-dialog.js` | Unchanged on branch (already has PR `hardware_live` dispatch) |
| `config/shipping.php` | Tracking sync default **false** (explicit opt-in at deploy) |
| `routes/web.php` | Unchanged on branch (already has `GET /dashboard/live/hardware`) |
| `resources/css/app.css` | Live overlay CSS preserved (+55 lines vs branch) |
| PR tracking / Ably stack | Unchanged from P-290 commits (command, service, migration, event, scheduler) |

## Production-only overlays not copied wholesale

Purchasing / historical-order / C360 **routes** and their controllers remain production-only. Branch restores historical-order **dashboard UI assets** and documents that production route merge is still required at deploy.

Production Hardware navigation uses `hw_scope` / `hw_filter`; branch retains `hw_queue` model for test compatibility while preserving live row/classifier/eligibility/table UX.

## Tracking flag

| Item | Value |
|------|-------|
| Code default (this branch) | `SHIPROCKET_TRACKING_SYNC_ENABLED` unset → **false** |
| Production today | env key **absent** (would have been implicit true before this change) |
| Deploy gate must set | `SHIPROCKET_TRACKING_SYNC_ENABLED=true` when ready to enable scheduler polling |
| Production `.env` changed this gate | **NO** |

## Migration

- `2026_09_15_100000_add_shiprocket_track_columns_to_shipments.php` present on branch (nullable additive columns)
- Production migration executed this gate: **NO**

## Mobile ("Awai Ser")

Not fixed (per gate scope). Preserved existing behavior: `.dashboard-cases-table-wrap { overflow: auto }` + `.dashboard-hardware-status` pill in `resources/css/app.css` at ~390px viewport.

## Validation (this session)

| Check | Result |
|-------|--------|
| PHPUnit (hardware classifier, tracking, Ably, work queue, navigation, regression) | PASS |
| Vitest `hardware-dashboard-live.test.js` | PASS (4) |
| Pint (changed files) | PASS |
| Vite build | PASS (`dashboard-CTECXT9l.js` includes historical-order + hardware live) |
| Production deploy | **NO** |

## Remaining deploy risks

1. Production `routes/web.php` surgical insert still required for any routes absent from branch (purchasing, historical API, C360 wallet routes).
2. Production Hardware scope/filter navigation differs from branch `hw_queue`; deploy overlay must not replace live `HardwareDashboardWorkspace` / `HardwareFulfilmentWorkQueue` wholesale.
3. Enable tracking sync explicitly in production `.env` only after migration + review.
4. Rebuild Vite assets on deploy from merged source (do not copy PR build output over live).
