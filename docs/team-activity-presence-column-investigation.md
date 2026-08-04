# Team Activity Presence Column — Investigation & Implementation

**Prompt:** P[04-08]-017  
**Date:** 2026-08-04  
**Type:** Presentation-only (no attendance, presence, status resolver, or payroll changes)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-presence-column-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-presence-column-investigation.canvas.tsx)

---

## Bottom line

Team Member column previously inlined live state (`● Active · L³³ᵐ · 37m`) beside the name. The Presence column only showed duration metrics (Today, Current, Sessions, Latest, Previous). Live operational state now lives in the Presence column as a stacked layout; metrics stay unchanged.

| Before | After |
|--------|-------|
| Member: name + inline status + late + duration | Member: name + calendar badge only |
| Presence: metrics grid only | Presence: live state stack + metrics grid |

---

## Objective

Replace inline format:

`● Active · L³³ᵐ · 37m`

With dedicated Presence presentation:

```
🟢 Active
L³³ᵐ
```

Secondary line (late) is visually subordinate. Session duration remains in the **Current** metric column — not duplicated under live state.

---

## Constraints (unchanged)

| Layer | Changed? |
|-------|----------|
| Attendance calculations | No |
| Presence calculations | No |
| Team Activity status resolver | No |
| Payroll | No |
| Team Activity data queries | No new queries / N+1 |
| Today / Current / Sessions / Latest / Previous | Unchanged |
| Latest Event column | Unchanged |

---

## Layout design

### Presence column structure

```
┌─────────────────────────────────────────────┐
│ Presence (header)                           │
│ [state] │ Today │ Current │ Sessions │ …    │
├─────────┼───────┼─────────┼──────────┼────┤
│ 🟢 Active│  2h   │  37m    │    1     │ …  │
│ L³³ᵐ    │       │         │          │    │
└─────────┴───────┴─────────┴──────────┴────┘
```

### Live presence stack (`team-activity-live-presence`)

1. **Primary:** status dot + label (`team-activity-status-badge`)
2. **Operational indicators** (`team-activity-operational-indicators`) — extensible list
   - Late: `L^{minutes}m` via `team-activity-operational-indicator--late`
   - Future: WFH, Training, Overtime, Early Login, Break, Auto Logged Out (slots ready, not implemented)
3. **Secondary context** (`team-activity-status-note`) — leave reason, shift metadata (not durations)

### Late indicator rules

Reuses P[04-08]-016 passthrough: `minutesLate` from attendance register via `AttendanceMatrixCellMapper::lateMinutesForDisplay`.

Shown only when register kind is **Late**. Hidden for Present, Leave, Holiday, Weekly Off, Half Day, Absent.

---

## Data flow (no new queries)

```
WorkforceAttendanceDay (existing)
  → WorkingHoursToday.minutesLate (existing passthrough)
  → TeamActivityPresenceMetrics.minutesLate
  → TeamActivityAgentRow.minutesLate
  → TeamActivityMemberStatusPresenter::lateDurationLabel()
  → live-presence Blade component
```

Status label and tone still come from `TeamActivityStatusResolver` (unchanged).

---

## Files modified

| File | Change |
|------|--------|
| `resources/views/components/team-activity/live-presence.blade.php` | New stacked presence component |
| `resources/views/dashboard/partials/team-activity-agent-row.blade.php` | Move state to Presence column; member = name only |
| `resources/views/dashboard/partials/team-activity-panel.blade.php` | Presence header grid (state + metrics) |
| `app/Support/Dashboard/TeamActivityMemberStatusPresenter.php` | `secondaryContextLabel`, `presenceAriaLabel` |
| `resources/css/app.css` | Presence layout, operational indicators, responsive grid |
| `tests/Unit/Dashboard/TeamActivityMemberStatusPresenterTest.php` | Secondary context + aria rules |
| `tests/Feature/DashboardTeamActivityUiTest.php` | Presence column UI assertions |
| `tests/Feature/DashboardTeamActivityTest.php` | Updated class expectations |

**Not modified:** `TeamActivityPanelService`, `TeamActivityStatusResolver`, attendance services, presence engine, payroll.

---

## Test results

Run:

```bash
php artisan test --filter='TeamActivityMemberStatusPresenterTest|DashboardTeamActivityUiTest|DashboardTeamActivityTest::test_team_activity_refresh_returns_panel_html_for_authorized'
```

Coverage:

| Case | Expect |
|------|--------|
| Late employee | `team-activity-operational-indicator--late` in Presence column |
| Non-late employee | Status only, no late indicator |
| Leave | On Leave + reason; no late |
| Weekly Off | Badge + offline; no late |
| Holiday | Holiday badge; no late |
| Layout | `team-activity-presence-layout`, metrics grid preserved |
| Calculations | Row DTO `minutesLate` unchanged from register |

---

## Rollback strategy

1. Revert Blade/CSS changes — restore `member-status` in Team Member column and metrics-only Presence column.
2. Revert presenter `secondaryContextLabel` / `presenceAriaLabel` if reverting aria behaviour.
3. No database migrations or config changes — rollback is a single deploy revert.
4. `live-presence.blade.php` can be deleted; `member-status.blade.php` remains for rollback path.

---

## Success criteria

The Team Activity Presence column is the single place for an employee’s live operational state, with secondary indicators (Late today) in a clean, extensible layout — without changing attendance or presence logic.
