# WFM Attendance & Team Activity Investigation

**Case:** C[04-08]-002  
**Prompt:** P[04-08]-010  
**Type:** Read-only investigation (no code changes)  
**Date:** 2026-08-04  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/wfm-attendance-team-activity-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/wfm-attendance-team-activity-investigation.canvas.tsx)

---

## Bottom line

Employees like Shashank and Shubhanshi show **Offline** instead of **Shift Not Started** because Team Activity only emits Shift Not Started when the work calendar status is `starts_later`. That status requires an effective `team_member_work_schedules` row **and** current time before shift start. With `no_schedule`, or once the shift window has opened with zero sessions today, the resolver falls through to **Offline**. That path is intentional in code, but it overloads Offline and diverges from the attendance register’s `not_started` (“Not logged in”) semantics. It is a product/UX defect for unscheduled or already-due employees, not a random presentation bug.

Attendance dashboard: summary cards are **selected-month person-day totals**, not today. Abbreviation `L` = Late (Leave is `V`). Placeholder tabs Leave / Calendar / Performance should not stay as permanent “Soon” chrome; Work Recognition already exists behind a feature flag.

---

## Investigation 1 — Team Activity Status

### Status resolution pipeline

```
Login / heartbeat / middleware
  → PresenceEngineService (WorkSession open/close)
  → AttendanceRegisterService refresh (workforce_attendance_days)
  → WorkforceAuthorityService snapshot
       (leave, calendar, presence, stored availability, block_reasons)
  → TeamAvailabilityOverviewService::operationalRoster
  → TeamActivityPanelService::build
       + TeamActivityPresenceMetricsService (sessionsToday)
       + latest AuditLog overlay
  → TeamActivityStatusResolver::resolve
  → TeamActivityWorkforceStatus::labelFor (UI label)
```

### Final status precedence (closed session)

Exact order in `TeamActivityStatusResolver::resolve`:

| # | Condition | Result | UI label |
|---|-----------|--------|----------|
| 1 | `block_reasons` contains `approved_leave` | `leave` | On Leave |
| 2 | `presence.session_open` | open-session branch | Active / Idle / Busy / Pending / On Break |
| 3 | last ended reason = `away_timeout` | `auto_logout` | Auto Logged Out |
| 4 | `work_calendar.status` = `starts_later` | `not_started_shift` | Shift Not Started |
| 5 | `sessionsToday` = 0 | `offline` | Offline |
| 6 | else | `off_duty` | Shift Ended |

Open-session branch (after leave check):

1. Allowed audit overlay (`on_ivr`, `break`, `waiting_customer`, `email`, `whatsapp`, `ira`) → Busy / Pending / On Break  
2. Lunch calendar or stored availability Busy → On Break  
3. Presence Idle → Idle  
4. Else → Active (`working`)

Weekly off / holiday never replace live status; they only appear as `calendarBadge` (“Weekly Off” / holiday name).

### Pipeline element review

| Area | Behaviour | Offline impact |
|------|-----------|----------------|
| Effective shift | `WorkCalendarService::todayStatusFor` + `scheduleFor` (effective-dated) | `starts_later` only if schedule exists and `now < work_start` |
| Shift timings | Per-user `TeamMemberWorkSchedule`; config defaults unused for calendar status | No schedule → `no_schedule`, never `starts_later` |
| Attendance register | `AttendanceDayCalculator` → `not_started` when working day + no sessions | Parallel system; Team Activity does **not** read register for live status |
| Session creation | `PresenceEngineService::startSession` on login / first attributable browser activity | Opens session → Active/Idle path |
| Presence / heartbeat | Open session + `last_activity_at`; idle/away thresholds | Only when session open |
| Logout | `closeSession` (manual / timeout); AwayTimeout → Auto Logged Out | Manual logout + sessions today → Shift Ended; zero sessions → Offline |
| Leave | Approved leave → On Leave (highest closed-path gate) | Pending leave does **not** change status |
| Weekly off / holiday | Calendar badge only | Status can still be Offline / Active / etc. |
| Schedule overrides | Effective-dated schedule rows (`effective_from` / `effective_to`), not a separate override table | Wrong/missing effective row → wrong calendar status |
| Precedence | Table above | Offline is the catch-all for “no session today” when not StartsLater |

### Why Offline instead of Shift Not Started

`isNotStartedShift()` is a single equality check:

```php
($workCalendar['status'] ?? '') === WorkCalendarDayStatus::StartsLater->value
```

`StartsLater` is returned only when:

1. Not holiday / not approved leave  
2. Schedule row exists  
3. Working day  
4. Not inside working hours  
5. Before shift start (and not overnight post-shift gap)

Otherwise, with no open session and `sessionsToday === 0`, status is **Offline**.

| Calendar status | Sessions today | Typical Team Activity label |
|-----------------|----------------|-----------------------------|
| `starts_later` | 0 | Shift Not Started |
| `working` / `lunch` | 0 | Offline |
| `no_schedule` | 0 | Offline |
| `outside_hours` | 0 | Offline |
| `weekly_off` / `holiday` | 0 | Offline (+ badge) |
| any | ≥1, closed | Shift Ended (or Auto Logged Out) |

### Affected employees (Shashank, Shubhanshi)

Local DB has no production roster. Analysis is from resolver code + prior production audits:

| Evidence | Source | Implication |
|----------|--------|-------------|
| Shubhanshi historically `schedule: none` | Jul 2026 attendance activity audit | Cannot ever reach `starts_later` → Offline whenever not logged in |
| Many agents lacked schedules | Same audit (11/13); schedule activation canvas | Systemic Offline vs Shift Not Started split |
| Pending leave does not set On Leave | Leave assignment safety (2026-08-03) | Pending leave ≠ On Leave; Offline still possible |
| Investigation time ~09:44 IST | Prompt timestamp | If they now have ~09:00 starts while peers start later, Offline during open window is code-correct |

**Per-person verdict (code-deterministic):**

| Person | Most likely path to Offline | Correct per current code? | Bug / gap? |
|--------|-----------------------------|---------------------------|------------|
| Shubhanshi | `no_schedule` **or** shift already open + 0 sessions + not approved leave | Yes — matches resolver | **Yes** — UX: should not look like peers’ “Shift Not Started”; register would say Not Started / Not logged in |
| Shashank | Same branches (verify schedule + shift start on prod) | Yes if those inputs hold | Same gap |

**Production verification (read-only):** for each user, dump `WorkCalendarService::todayStatusFor`, `shift_times`, open `WorkSession`, `WorkingHoursToday.sessionCount`, approved leave, and resolver output. Compare peers who show Shift Not Started — they must have `starts_later`.

### Root cause

1. **Primary:** Shift Not Started is gated exclusively on calendar `starts_later`, which requires a schedule before shift start.  
2. **Secondary:** Offline is overloaded (unscheduled, due-but-not-logged-in, off-hours never logged in, off-day never logged in).  
3. **Tertiary:** Team Activity ignores attendance register day status for the live label, so register `not_started` and panel Offline diverge.  
4. **Not the cause:** Heartbeat failure alone (that yields Away/Idle/Auto Logged Out when a session existed). Logout alone does not force Offline if `sessionsToday > 0` (Shift Ended).

### Architecture review

- Clear separation: presence write path → attendance register → live panel composition.  
- Live status is presence + calendar + leave, not register — fine for “now,” confusing when labels collide with attendance language.  
- `Logout` enum case exists but is unused by the resolver.  
- No-schedule assignment eligibility is open (`isEligibleForAssignment` returns true), while Team Activity still labels Offline — inconsistent ops story.

---

## Investigation 2 — Attendance Dashboard

### Attendance status abbreviations

Actual short labels (`AttendanceMatrixCellKind::shortLabel`):

| Kind | Short | Full label | Clarity |
|------|-------|------------|---------|
| Present | P | Present | Clear |
| Absent | A | Absent | Clear |
| Late | **L** | Late | Ambiguous with Leave |
| Leave | **V** | Leave | Opaque (looks like Vacation) |
| Half Day | **H** | Half Day | Ambiguous with Holiday |
| Holiday | **N** | Holiday | Opaque |
| Weekly Off | W | Weekly off | Acceptable |
| Extra | E | Extra working | Acceptable |

Cells already have rich Bootstrap tooltips (date, kind, login window, minutes late, hours). Legend on the page spells out full words, but the matrix still shows single letters.

**Recommendation (no behaviour change yet):**

1. Prefer clearer shorts: Late → `LT`, Leave → `LV` (or keep Leave as full-word chip), Half Day → `HD`, Holiday → `HO`.  
2. Or keep single letters but make the in-matrix legend sticky and match shorts exactly (`L` Late, `V` Leave — document V).  
3. Do **not** use `L` for Leave; that would worsen collision with Late.

### Dashboard summary cards

Cards: Present, Absent, Leave, Late, Holiday.

Built in `MonthlyAttendanceMatrixService::build` by summing each member’s month cell counts into `AttendanceMatrixTeamSummary`.

| Question | Finding |
|----------|---------|
| Today or selected month? | **Selected month** person-days (all members × days through today in month) |
| Clear to users? | **Weak** — labels look like today’s headcount; only page subtitle mentions the month |
| Operational value? | Useful for **month payroll/HR overview**; poor for **live workforce status** |

**Suggested improvements:**

1. Relabel: “Month totals (person-days)” / “Present days”.  
2. Add a separate **Today** strip: Present / Absent / On Leave / Late / Not logged in (from today’s register or Team Activity).  
3. Optionally drop Holiday from the top strip (visible in matrix columns) and add Absent rate or Not-logged-in-today.

### Placeholder tabs

From `workforce-management/partials/workspace-nav.blade.php`:

| Tab | Status | Exists elsewhere? | Recommendation |
|-----|--------|-------------------|----------------|
| Attendance | Live | This module | Keep |
| Payroll / Salaries | Gated by payroll access | Yes | Keep as-is |
| Work Recognition | Enabled only if `workforce_recognition.enabled` + permission; else disabled “Soon” but still **visible** | Full module under `workforce-management/recognition` | Show when enabled; **hide** when disabled (or single “Soon” only in backlog UI) |
| Leave | `enabled: false`, `url: null` | Yes — `leave-requests.index` / My Leave / admin leave | **Link** to existing Leave module (permission-aware); do not keep dead Soon tab |
| Calendar | Disabled Soon | Calendar logic in `WorkCalendarService`; no WFM calendar UI | **Hide** until a real calendar surface ships |
| Performance | Disabled Soon | Personal “My Performance” exists; no WFM Performance hub | **Hide** until designed; avoid duplicate half-nav |

**Cleanest UX:** ship only real destinations; use feature flags for visibility; preserve extensibility by keeping tab config data-driven (already the pattern) with `visible: false` for unfinished modules.

---

## Files involved

### Team Activity / presence

- `app/Support/Dashboard/TeamActivityStatusResolver.php`
- `app/Support/Dashboard/TeamActivityWorkforceStatus.php`
- `app/Services/Dashboard/TeamActivityPanelService.php`
- `app/Services/Dashboard/TeamActivityPresenceMetricsService.php`
- `app/Services/Operations/TeamAvailabilityOverviewService.php`
- `app/Services/Operations/WorkforceAuthorityService.php`
- `app/Services/Operations/WorkCalendarService.php`
- `app/Services/Operations/PresenceEngineService.php`
- `app/Http/Controllers/PresenceHeartbeatController.php`
- `app/Enums/TeamActivityStatus.php`
- `app/Enums/WorkCalendarDayStatus.php`
- `config/dashboard-team-activity.php`
- `resources/views/dashboard/partials/team-activity-panel.blade.php`
- `tests/Unit/Dashboard/TeamActivityStatusResolverTest.php`
- `tests/Feature/DashboardTeamActivityTest.php`

### Attendance dashboard

- `app/Services/Workforce/MonthlyAttendanceMatrixService.php`
- `app/Support/Workforce/AttendanceMatrixCellMapper.php`
- `app/Enums/AttendanceMatrixCellKind.php`
- `app/Services/Operations/AttendanceDayCalculator.php`
- `app/Services/Operations/AttendanceRegisterService.php`
- `resources/views/workforce-management/attendance/index.blade.php`
- `resources/views/workforce-management/partials/attendance-matrix.blade.php`
- `resources/views/workforce-management/partials/attendance-cell.blade.php`
- `resources/views/workforce-management/partials/workspace-nav.blade.php`
- `app/Http/Controllers/LeaveRequestController.php` (existing Leave module)
- `routes/web.php` (`leave-requests` resource)

---

## UI/UX recommendations

1. **Status labels:** Split Offline into clearer states — e.g. Not Logged In (during shift), Shift Not Started (before shift / using defaults when unscheduled), No Schedule, Shift Ended.  
2. **Unscheduled employees:** Either require schedules for attendance-tracked roles, or treat config defaults as temporary calendar for StartsLater / working window.  
3. **Align language** with attendance register (`not_started` ↔ Not Logged In).  
4. **Abbreviations:** `LT` / `LV` / `HD` / `HO` or sticky legend tied to shorts.  
5. **Summary cards:** Mark as month person-days; add Today strip for ops.  
6. **Nav:** Hide unfinished tabs; link Leave to `leave-requests`; show Recognition only when enabled.

---

## Risks

| Risk | Severity | Notes |
|------|----------|-------|
| Changing Offline → Not Logged In without schedule policy | Medium | May surprise ops who treat Offline as “unavailable for assign” language |
| Inventing StartsLater from config defaults without admin schedule | High | Changes late/OT/assignment eligibility assumptions |
| Abbreviation change mid-month | Low | Training / screenshot drift |
| Hiding Soon tabs | Low | Stakeholders lose roadmap visibility in chrome |
| Linking Leave into WFM without permission checks | Medium | Must reuse existing gates |

---

## Rollback strategy

Investigation only — no production behaviour change. When fixes ship later:

1. Feature-flag label/precedence changes (`dashboard-team-activity` config).  
2. Keep resolver unit tests as contract; add cases for `no_schedule` and mid-shift zero sessions.  
3. Abbreviation / nav / summary copy changes are view-only — revert Blade/enum shorts independently of presence write path.  
4. Never mix attendance register rewrite into a label-only fix.

---

## Test recommendations

1. Unit: `no_schedule` + zero sessions → document current Offline; assert desired future label once product decides.  
2. Unit: `starts_later` vs `working` + zero sessions (Shift Not Started vs Not Logged In / Offline).  
3. Feature: fixture peers with 10:00 start vs 09:00 start at 09:44 — reproduces Shashank/Shubhanshi-style split.  
4. Feature: approved leave beats Offline; pending leave does not.  
5. Matrix: tooltip contains “Late” when short is `L`/`LT`; Leave short never equals Late.  
6. Summary: assert team Present equals sum of member present days for selected month, not “count of online now”.  
7. Nav: Leave tab links to `leave-requests.index` when permitted; Recognition hidden when flag false.

---

## Do not implement yet

No code changes until product confirms:

1. Desired label for unscheduled + not logged in  
2. Desired label for mid-shift + not logged in  
3. Whether config defaults may drive StartsLater  
4. Abbreviation scheme and summary card redesign
