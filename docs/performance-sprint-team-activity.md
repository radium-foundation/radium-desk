# Performance Sprint — Team Activity Lazy Load

**Date:** 2026-08-05  
**Type:** Implementation report  
**Parent:** [radium-desk-performance-audit.md](./radium-desk-performance-audit.md) (Q4)  
**Scope:** Defer Team Activity `build()` from dashboard SSR. No Canvas.

---

## Verdict

Dashboard first paint no longer runs `TeamActivityPanelService::build()`. SSR renders a collapsed **shell** only. The roster (permissions-gated refresh endpoint) loads after the panel expands — same expand / poll / search-collapse / badges behaviour as before.

| Constraint | Status |
|------------|--------|
| No `build()` on dashboard SSR | Yes |
| Shell when `teamActivity.view` + feature enabled | Yes |
| Load via existing `GET /dashboard/team-activity` on expand | Yes |
| Permissions / badges / refresh / search / expand unchanged | Yes |
| No UI redesign | Yes |
| No business-logic changes in panel build | Yes |

---

## Before / after

| Path | Before | After |
|------|--------|-------|
| `GET /dashboard` | Always `build()` when permitted (even if collapsed) | Permission check only; Blade shell (`data-team-activity-lazy`) |
| Panel expand | Refresh + 30s poll (already) | Same — first expand hydrates shell |
| Session restores expanded | Refresh only if agent rows already expanded in DOM | Always hydrate + poll |
| Empty roster | Panel omitted from SSR | Shell shown; first refresh removes panel if empty (same JSON contract) |

---

## Behaviour preserved

- **Permissions:** Shell only when `teamActivity.view` and `dashboard-team-activity.enabled`. Refresh endpoint still gates `build()` / HTML.
- **Badges:** Still resolved inside `build()` / refresh HTML — not on SSR.
- **Refresh / poll:** Unchanged 30s poll while expanded and user active; skipped when collapsed / hidden / idle.
- **Search:** In-panel search interaction still collapses Team Activity (existing listener).
- **Expand:** Panel toggle and per-agent row expand / history fetch unchanged.

---

## Files touched

| File | Change |
|------|--------|
| `app/Http/Controllers/DashboardController.php` | Drop `TeamActivityPanelService`; pass `teamActivityCanView` |
| `resources/views/dashboard/index.blade.php` | Include panel when can view (not when non-empty panel) |
| `resources/views/dashboard/partials/team-activity-panel.blade.php` | Optional `$panel`; null = lazy shell |
| `resources/js/dashboard-team-activity.js` | Hydrate whenever panel restored expanded |
| `tests/Feature/DashboardTeamActivityTest.php` | Assert lazy shell, no roster markup on SSR |
| `tests/js/dashboard-team-activity.test.js` | Expand + restored-expanded hydrate coverage |

Unchanged: `DashboardTeamActivityController`, `TeamActivityPanelService::build()` / `render()`, badge resolver, presence / KPI / pending services.

---

## Flow

```
DashboardController::index
  └─ can teamActivity.view && enabled?
       └─ Blade shell (header + empty body, data-team-activity-lazy)

User expands panel (or session restores expanded)
  └─ GET /dashboard/team-activity
       └─ TeamActivityPanelService::build() + render()
            └─ replace panel HTML (badges, roster, history as before)
```

---

## Verification

```bash
php artisan test --filter=DashboardTeamActivityTest::test_dashboard_page_includes_team_activity_attributes
npm test -- --run tests/js/dashboard-team-activity.test.js
```

Manual: open dashboard as supervisor → collapsed Team Activity header only → expand → roster appears → collapse → no poll → badges still on rows after load.

---

## Out of scope (still audit backlog)

- Team Activity metrics cache 15–30s (M10)
- Email Intake KPI cache / read-only categorize
- Cross-request operator dashboard snapshot
- Active-incident hydrate reduction
