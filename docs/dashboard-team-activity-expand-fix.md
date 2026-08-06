# Dashboard Team Activity — Expand Fix (Lazy-Shell Regression)

**Date:** 2026-08-06  
**Type:** Bugfix  
**Related:** [`docs/dashboard-production-investigation-2026-08-06.md`](./dashboard-production-investigation-2026-08-06.md), [`docs/performance-sprint-team-activity.md`](./performance-sprint-team-activity.md)

---

## Root cause

v4.0.4 (`5547a2d`) deferred Team Activity roster build from dashboard SSR. The page ships a collapsed **lazy shell**; expand must hydrate via `GET /dashboard/team-activity`.

The hydrate path failed closed:

1. Network / HTTP / JSON failures were swallowed in an empty `catch`.
2. `empty: true` (including unauthorized responses) called `currentPanel.remove()`, deleting the widget.
3. With an empty shell and no fallback UI, expand looked broken (chevron might flip, body stayed blank, or the panel vanished).

Lazy loading itself was correct for performance. The regression was fail-closed client handling.

---

## Why the regression occurred

| Before lazy shell | After lazy shell (broken) |
|-------------------|---------------------------|
| SSR already rendered roster HTML | SSR body empty until fetch |
| Expand mostly revealed existing DOM | Expand **required** a successful fetch |
| Fetch failure still left prior roster | Fetch failure → blank body or panel removed |

---

## Fix summary

Keep lazy hydration. Make expand always keep the shell and surface failures.

| Requirement | Implementation |
|-------------|----------------|
| Expand always works | Toggle still opens shell; hydrate runs with `force: true` |
| Never silently fail | Dev `console.warn('[team-activity] …')` on hydrate/poll failures |
| Never remove widget | Removed `currentPanel.remove()` path entirely |
| Keep shell + Retry UI | Inline “Unable to load team activity. Retry.” + `[data-team-activity-retry]` |
| `empty: true` = genuine empty roster | API returns `403` + `error: forbidden` when unauthorized; `empty: true` + `reason: roster_empty` only for empty roster |
| Polling preserved | 30s poll while expanded; poll failures do **not** wipe a hydrated roster |
| No duplicate listeners | `data-team-activity-bound` guard; re-bind only after `replaceWith` on a new node |
| No SSR roster rebuild | Dashboard still includes shell only (`teamActivityCanView`) |

---

## Files changed

| File | Change |
|------|--------|
| `resources/js/dashboard-team-activity.js` | Hydrate loading/error/retry; never remove panel; preserve roster on poll failure; dev warnings; stable HTML ignores collapsed attrs |
| `app/Http/Controllers/DashboardTeamActivityController.php` | Unauthorized → `403` / `error: forbidden`; genuine empty → `empty: true` + `reason: roster_empty` |
| `resources/css/app.css` | Lightweight `.team-activity-hydrate-error` layout |
| `tests/js/dashboard-team-activity.test.js` | Expand/collapse/retry/empty/poll-failure/listener coverage |
| `tests/Feature/DashboardTeamActivityTest.php` | Forbidden + genuine-empty API contracts |
| `docs/dashboard-team-activity-expand-fix.md` | This report |

**Unchanged (performance preserved):**

- `DashboardController` — no `TeamActivityPanelService::build()` on SSR
- Lazy shell markup in `team-activity-panel.blade.php`
- Poll interval / idle skip / live-dashboard isolation

---

## Why the fix preserves the performance optimization

SSR still does **not** call `TeamActivityPanelService::build()`. First paint remains a header-only shell. Roster work still happens only on expand (or session-restored expanded hydrate) via the existing refresh endpoint. The fix only changes client failure semantics and clarifies the API empty vs forbidden contract.

---

## Behaviour after fix

```text
Expand
  → remove is-collapsed
  → show “Loading team activity…”
  → GET /dashboard/team-activity
       success + html     → replaceWith roster, re-bind, poll
       success + empty    → keep shell, show “No team members to show.”
       HTTP/network error → keep shell, show Retry UI (+ console.warn in DEV)
Retry button
  → force hydrate again
Poll (expanded)
  → refresh as before
  → on failure: keep current roster, warn in DEV (no wipe)
Collapse
  → stop poll (unchanged)
```

---

## Test results

```bash
npm test -- --run tests/js/dashboard-team-activity.test.js
# ✓ 18 tests passed

php artisan test --filter='DashboardTeamActivityTest::test_team_activity_refresh_returns_forbidden_without_permission|DashboardTeamActivityTest::test_team_activity_refresh_marks_genuine_empty_roster|DashboardTeamActivityTest::test_dashboard_page_includes_team_activity'
# ✓ passed
```

| Scenario | Result |
|----------|--------|
| First expand | Hydrates roster from API |
| Collapse | Stops poll; shell remains |
| Expand again | Re-hydrates; interactions work |
| Poll while expanded | Refreshes; listeners re-bound after replace |
| Poll while collapsed | No fetch |
| Retry after failed request | Error UI → Retry → roster recovers |
| Genuine `empty: true` | Shell kept; empty message; panel not removed |
| Unauthorized refresh | HTTP 403 (not empty roster) |
| Duplicate listeners | `data-team-activity-bound` prevents stacking |
| Console errors | None expected; DEV warns on failures only |

---

## Manual verification

1. Open dashboard as a user with `teamActivity.view`.
2. Expand Team Activity → roster loads.
3. Collapse → expand again → roster loads.
4. Leave expanded ~30s → poll refresh without console errors.
5. Throttle/block `/dashboard/team-activity` → expand shows Retry UI; panel remains.
6. Click Retry → roster recovers when the endpoint is healthy again.
