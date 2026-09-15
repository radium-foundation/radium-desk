# PR #8 production-navigation-compatible realtime adaptation (P-07-09-294)

**Prompt ID:** RadiumDesk-P-07-09-294  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**PR:** https://github.com/radium-foundation/radium-desk/pull/8  
**Branch:** `cursor/hw-ably-shiprocket-sync-c269`

## Objective

Adapt PR #8 Hardware Dashboard realtime functionality to the verified live Radium Desk navigation model (`hw_scope` / `hw_filter`) while preserving all verified production-only overlays from P-07-09-292.

**This gate:** implement / test / commit / push only. **No production deploy.**

## Verdict

**Implementation complete on branch.** Production deploy and migration remain separate gates.

## Navigation adaptation

| Item | Result |
|------|--------|
| `HardwareWorkspaceScope` (active/shipped) | Restored from live overlay |
| `HardwareWorkspaceFilter` (all/ready/exceptions/pickup/scheduled) | Preserved |
| `hardware-workspace-nav.blade.php` | Added; replaces inline `hw_queue` chips |
| Legacy `hw_queue` | Compatibility layer in `HardwareDashboardWorkspace::resolveScope/resolveFilter` |
| Production navigation semantics | Preserved (Hardware / Shipped / All / Ready / Exceptions / Pickup / Scheduled) |

## Realtime adaptation

| Item | Result |
|------|--------|
| `HardwareDashboardLiveService::livePayload()` | Calls `dashboard(..., HardwareWorkspaceScope, HardwareWorkspaceFilter)` |
| `DashboardLiveController::hardware()` | Accepts `hw_scope`, `hw_filter`, search, date range; legacy `hw_queue` mapped safely |
| Payload | `scope_counts`, `filter_counts`, row HTML, `hardware_count` |
| `#dashboard-hardware-body` | Present on live hardware table body |
| `hardware-dashboard-live.js` | Patches `[data-hardware-scope-count]` / `[data-hardware-filter-count]` |
| Ably | Unchanged architecture; clients refetch `GET /dashboard/live/hardware` with scope/filter |

## Live overlays preserved

Purchasing / historical-order / C360 routes unchanged. Date/B2B columns, `HardwareConfigurableVariantDisplay`, historical-order JS/modal, and live `app.css` preserved from P-07-09-292.

## Shiprocket / migration

| Item | Value |
|------|-------|
| `SHIPROCKET_TRACKING_SYNC_ENABLED` default | **false** |
| Status 19 mapping | **UNKNOWN** (unchanged) |
| Tracking migration | Present; **not executed** against production |

## Validation

| Check | Result |
|-------|--------|
| PHPUnit (focused hardware/realtime/overlay/shiprocket) | PASS |
| Vitest (`hardware-dashboard-live.test.js`) | PASS (4) |
| Pint (dirty PHP) | PASS |
| Vite build | PASS; bundle contains historical-order + hw_scope live selectors |

## Mobile follow-up

Known "Awai Ser" clipping in `.dashboard-hardware-status` not addressed in this gate.
