# P-11-42 — Dashboard synchronous critical-path audit (read-only design)

Companion to hardware count cache fix. **No lazy-load implementation in P-11-42.**

## Authenticated SSR path (`DashboardController::index`)

| Operation | Sync today? | Cost (P-11-39 prod / local) | Initial usability | Lazy candidate? |
|---|---|---:|---|---|
| Workspace resolve + redirect | Yes | Low | Critical | No |
| `dashboardChipCounts` (+ hardware `chipCount` → `scopeCounts`) | Yes | Medium (2–3 SQL when cache cold) | Critical (queue chips) | Partial — chip integers can poll via `/dashboard/live` counts |
| `recentServiceCases` (35 rows) | Yes | Medium–High (snapshot + row map) | Critical | Partial — skeleton + `dashboard.live.rows` pattern exists |
| `statsFor` / `statsForHardwareShell` | Yes | **Very high** for admins | Mixed | Partial |
| `hardware_stuck_orders` (`stuckOrderSummary` → `filterCounts`) | **No (P-11-43)** | **~11 COUNT queries, ~13–15s prod** when loaded via `GET /dashboard/hardware/stuck-summary` | Secondary (admin KPI card) | **Deferred from SSR — implemented P-11-43** |
| `hardwareDashboard->shell` (hardware queue) | Yes | Low shell / defers rows | Critical when queue=hardware | Already defers rows (`data-hardware-defer-rows`) |
| Embedded workspace panel | Yes (if query) | Variable | Critical when embedded | No |
| Team activity / audit streams (view gated) | Partial in view | Medium | Secondary | Yes |
| TrackTeamMemberActivity middleware | Yes | Low–medium | Critical (presence) | No |

## Live refresh (`DashboardLiveController::refresh`)

| Operation | Sync (XHR) | Cost (P-11-39) | Usability | Lazy candidate? |
|---|---|---:|---|---|
| Full `liveMetricsFor` + KPI HTML | Yes | **~14–16s prod** | Secondary on first paint | **Yes — split `kpis_only` + stagger** |
| Row patch (`rows`, `hardware`) | On demand | Medium | Critical for live updates | Already async endpoints |

## Classification summary

- **Critical:** layout shell, queue navigation, first page of service cases (or explicit empty state), auth/presence, hardware shell when on hardware queue.
- **Secondary:** admin hardware stuck card, automation health block, todo widget, full live JSON on load.
- **Deferred (recommended next prompt):** `hardware_stuck_orders` SSR; warm `filterCounts` only via cache until deferred.

## Lazy-loading proposal (follow-up prompt scope)

1. **SSR:** Stop calling `stuckOrderSummary()` inside `buildFastChangingStats`; render card shell with `data-defer-hardware-stuck="1"` and skeleton (no zero counts).
2. **Endpoint:** Add `GET dashboard/hardware/stuck-summary` (auth: `dashboard.hardware.view` + admin queues) returning JSON breakdown; reuse `HardwareDashboardWorkspace::stuckOrderSummary()` (cached).
3. **Client:** Load after `DOMContentLoaded`; single flight + 30s client backoff aligned with TTL; show “Updating…” not `0`.
4. **Live poll:** Keep `GET /dashboard/live?kpis_only=1` for KPI strip; do not bundle 500KB row payloads on timer when `dashboard.live_mode=auto`.
5. **Tests:** Extend `AdminHardwareStuckOrderNavigationTest`, `HardwareDashboardAsyncShellTest` patterns.

## Files (expected follow-up)

- `app/Services/DashboardService.php` (guard stuck summary on SSR)
- `resources/views/dashboard/partials/admin-hardware-stuck-card.blade.php`
- `resources/js/dashboard/*` (defer loader)
- `routes/web.php` + thin controller method
- Tests under `tests/Feature/Dashboard/`
