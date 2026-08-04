# Team Activity Presence Legend — Investigation & Implementation

**Prompt:** Presence status legend  
**Date:** 2026-08-04  
**Type:** Presentation-only (no attendance, presence, or Team Activity logic changes)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-presence-legend-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-presence-legend-investigation.canvas.tsx)

---

## Bottom line

A small info icon beside the Presence column header opens the existing dashboard premium tooltip with a compact abbreviation legend. No table space is permanently consumed; no new queries.

| Requirement | Result |
|-------------|--------|
| Help icon beside Presence | `bi-info-circle` trigger |
| Hover / focus shows legend | Bootstrap tooltip + premium template |
| Lightweight | Overlay only; no permanent row |
| Existing components | Reuses `data-dashboard-tooltip` + premium tooltip styling |
| Logic unchanged | Presentation catalog only |
| Queries | Zero additional |

---

## Objective

New users understand Presence abbreviations in one click; experienced users keep the compact dashboard.

---

## Legend

| Abbr | Meaning |
|------|---------|
| A | Active |
| I | Idle |
| P | Pending |
| ALO | Auto Logged Out |
| LV | On Leave |
| NLI | Not Logged In |
| SNS | Shift Not Started |
| NS | No Schedule |
| L | Late |
| OT | Overtime (future) |
| WFH | Work From Home (future) |

Future entries render muted with an italic `(future)` suffix.

---

## Approach

### UI placement

```
Presence ⓘ
[state] │ Today │ Current │ Sessions │ Latest │ Previous
```

The icon sits in `team-activity-grid-header__title-row` next to the Presence title. Legend content lives in a sibling `<template class="dashboard-tooltip-template">` — same pattern as Online Users / SLA tooltips.

### Interaction

- Hover or keyboard focus on the info button shows the tooltip.
- Trigger is a `<button>` with `aria-label="Presence status legend"` and `cursor: help`.
- Tooltip uses `data-bs-container="body"` so it is not clipped by the panel.

### Refresh survival

Team Activity replaces panel HTML on poll. After `applyPanelHtml`, the module calls `initTooltips(nextPanel)` so the legend trigger is re-bound without N+1 or new endpoints.

### Data source

`TeamActivityPresenceLegend::entries()` is a static presentation catalog. It is not wired into the status resolver, attendance register, or presence engine.

---

## Files modified

| File | Change |
|------|--------|
| `app/Support/Dashboard/TeamActivityPresenceLegend.php` | Abbreviation catalog |
| `resources/views/components/team-activity/presence-legend.blade.php` | Header icon + tooltip template |
| `resources/views/dashboard/partials/team-activity-panel.blade.php` | Include legend component |
| `resources/js/dashboard-team-activity.js` | Re-init tooltips after HTML replace |
| `resources/css/app.css` | Trigger + compact legend styles |
| `tests/Unit/Dashboard/TeamActivityPresenceLegendTest.php` | Catalog assertions |
| `tests/Feature/DashboardTeamActivityUiTest.php` | Legend markup in Presence header |

**Not modified:** attendance services, presence engine, status resolver, panel build queries, payroll.

---

## Test results

```bash
php artisan test --filter='TeamActivityPresenceLegendTest|DashboardTeamActivityUiTest::test_presence_column_layout'
npx vitest run tests/js/dashboard-team-activity.test.js
```

| Suite | Result |
|-------|--------|
| Presence legend unit + UI layout | Passed |
| Team Activity JS (refresh / poll) | Passed (10) |

Coverage:

- Catalog includes A–WFH with future flags for OT / WFH
- Panel HTML includes info trigger, legend title, abbr rows, `(future)`
- Existing Team Activity JS behaviour preserved after tooltip re-init

---

## Rollback strategy

1. Remove `<x-team-activity.presence-legend />` from the panel header (restore plain `Presence` title).
2. Delete `presence-legend.blade.php` and `TeamActivityPresenceLegend.php`.
3. Revert `initTooltips(nextPanel)` in `dashboard-team-activity.js` if undesired.
4. Revert related CSS and tests.

No migrations, config, or API changes — single deploy revert is sufficient.

---

## Success criteria

New users can understand every Presence abbreviation within one click, while experienced users continue to enjoy the compact operational dashboard.
