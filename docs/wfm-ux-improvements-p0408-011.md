# WFM UX Improvements — Labels, Month Totals, Navigation

**Case:** C[04-08]-003  
**Prompt:** P[04-08]-011  
**Date:** 2026-08-04  
**Type:** Approved UX implementation (no attendance/presence engine changes)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/wfm-ux-improvements-p0408-011.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/wfm-ux-improvements-p0408-011.canvas.tsx)

---

## Bottom line

Team Activity now distinguishes **Shift Not Started**, **Not Logged In**, **No Schedule**, **Shift Ended**, and **On Leave** instead of overloading Offline. Attendance short codes are unchanged; legend and tooltips show `P · Present` style pairs. Summary cards are labeled as **Month totals** (person-days). WFM nav links Leave to the existing module, hides Calendar/Performance, and shows Work Recognition only when enabled + permitted.

Attendance calculations, payroll math, and presence session/heartbeat engines were not modified.

---

## Architecture summary

```
Presence / attendance engines (unchanged)
        │
        ▼
TeamAvailabilityOverviewService / WorkCalendar / presence snapshots
        │
        ▼
TeamActivityStatusResolver  ← classification only (new zero-session branches)
        │
        ▼
TeamActivityWorkforceStatus labels (presentation)
        │
        ▼
Dashboard Team Activity panel

Attendance register / MonthlyAttendanceMatrixService (unchanged math)
        │
        ▼
AttendanceMatrixCellMapper tooltips + Blade legend / month-total chrome
        │
        ▼
workspace-nav (visibility + Leave link)
```

### Final Team Activity status precedence

Closed-session path after leave / open session / auto-logout checks:

| # | Condition | Status | UI label |
|---|-----------|--------|----------|
| 1 | `approved_leave` block reason | `leave` | On Leave |
| 2 | Open work session | open-session branch | Active / Idle / Busy / Pending / On Break |
| 3 | Last end = away timeout | `auto_logout` | Auto Logged Out |
| 4 | Calendar `starts_later` | `not_started_shift` | Shift Not Started |
| 5a | Zero sessions + `no_schedule` | `no_schedule` | No Schedule |
| 5b | Zero sessions + working / lunch / outside_hours | `not_logged_in` | Not Logged In |
| 5c | Zero sessions + weekly_off / holiday | `offline` | Offline (badge: Weekly Off / Holiday) |
| 6 | Sessions today ≥ 1, closed | `off_duty` | Shift Ended |

Open-session branch unchanged: allowed audit overlay → break/lunch → Idle → Active.

---

## What changed

### 1. Team Activity status labels

- Added enum cases `not_logged_in`, `no_schedule`.
- `resolveZeroSessionsStatus()` maps calendar context when `sessionsToday === 0`.
- Labels via `TeamActivityWorkforceStatus` + config.
- Sorter / member status presenter treat new statuses like other unavailable tiers.
- Presence engine, session creation, heartbeat, attendance register: **untouched**.

### 2. Attendance abbreviations

- Short codes unchanged: P A L V H N W E.
- Legend: `P · Present`, `L · Late`, `V · Leave`, etc.
- Tooltips: status line uses `kindLegendLabel()` (`L · Late`).

### 3. Month totals cards

- Same five metrics and same sums.
- Section titled **Month totals** with “Person-days for {month}”.
- Each card shows **Month total** meta text.

### 4. Workforce navigation

| Tab | Behaviour |
|-----|-----------|
| Attendance / Payroll / Salaries | Unchanged (payroll still permission-gated) |
| Leave | Links to `leave-requests.index` when `viewAny` LeaveRequest |
| Work Recognition | Visible only if flag + `workforce.recognition.view` |
| Calendar / Performance | Hidden (removed from tab list) |
| Soon placeholders | Removed |

---

## Files modified

### Team Activity

- `app/Enums/TeamActivityStatus.php`
- `app/Support/Dashboard/TeamActivityStatusResolver.php`
- `app/Support/Dashboard/TeamActivityWorkforceStatus.php`
- `app/Support/Dashboard/TeamActivityRowSorter.php`
- `app/Support/Dashboard/TeamActivityMemberStatusPresenter.php`
- `config/dashboard-team-activity.php`

### Attendance UX

- `app/Support/Workforce/AttendanceMatrixCellMapper.php`
- `resources/views/workforce-management/attendance/index.blade.php`
- `resources/css/app.css`

### Navigation

- `resources/views/workforce-management/partials/workspace-nav.blade.php`

### Tests

- `tests/Unit/Dashboard/TeamActivityStatusResolverTest.php`
- `tests/Unit/Dashboard/TeamActivityWorkforceStatusTest.php`
- `tests/Unit/Workforce/AttendanceMatrixCellMapperTest.php`
- `tests/Feature/DashboardTeamActivityTest.php`
- `tests/Feature/MonthlyAttendanceMatrixTest.php`
- `tests/Feature/Workforce/WorkforceManagementNavTest.php` (new)

---

## Test results

All targeted suites green (2026-08-04):

| Suite | Result |
|-------|--------|
| `TeamActivityStatusResolverTest` | 17 passed |
| `TeamActivityWorkforceStatusTest` | 2 passed |
| `AttendanceMatrixCellMapperTest` | 5 passed |
| `TeamActivityMemberStatusPresenterTest` | passed |
| `TeamActivityRowSorterTest` | passed |
| `DashboardTeamActivityTest` | 11 passed |
| `MonthlyAttendanceMatrixTest` | 8 passed |
| `WorkforceManagementNavTest` | 2 passed |

Verified behaviours:

- Resolver labels for before-shift / mid-shift / no-schedule / leave / shift ended
- Legend + tooltip short-code consistency
- Month totals copy on attendance index
- Leave nav link; Calendar/Performance/Soon absent; Recognition gated by flag

---

## Rollback strategy

1. Revert the listed files (git revert of this change set).
2. No migrations or `release.json` involvement.
3. Presence/attendance data and payroll locks are unaffected — rollback is UI/resolver-classification only.
4. Feature flag for Recognition was already required; nav hide is additive safety.

---

## Success criteria

| Criterion | Status |
|-----------|--------|
| Clearer Team Activity labels; Offline not used for mid-shift / unscheduled | Met |
| Attendance / payroll / presence engines unchanged | Met |
| Abbreviations preserved; legend/tooltips aligned | Met |
| Cards clearly month person-day totals | Met |
| Leave linked; Calendar/Performance hidden; Recognition flag-gated | Met |
| Tests covering labels, legend, nav, month totals | Met |
