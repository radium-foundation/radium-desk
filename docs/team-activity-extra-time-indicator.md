# Team Activity Extra Time (XT) Indicator — Design & Implementation Plan

**Date:** 2026-08-05  
**Type:** Presentation-only feature (operational visibility)  
**Prerequisite:** [team-activity-overtime-investigation.md](./team-activity-overtime-investigation.md)  
**Canvas:** None (markdown-only deliverable)

---

## Bottom line

Attendance and payroll **do not expose pre-shift extra seconds**. Post-shift wall-clock time outside the shift is stored as **`overtime_seconds`** (payroll OT) on closed sessions only. **XT requires one new read-only calculator** that combines:

- **Pre-shift:** always computed at read time (never persisted)
- **Post-shift:** reuse stored `overtime_seconds` on closed sessions; live projection for open sessions via the same formula already used by `PresenceEngineService`

XT is surfaced only in Team Activity. Payroll OT, Attendance Matrix, reports, and leave rules are **unchanged**.

| Item | Decision |
|------|----------|
| Label | `XT` = Extra Time |
| Scope | Team Activity Presence column only |
| Persisted? | **No** — computed on panel build |
| vs Payroll OT | Separate indicator; do **not** rename or surface payroll OT as XT |
| vs `overtime_seconds` | Post-shift portion **equals** payroll OT math; pre-shift is new read-only math |

---

## Definition

**XT** = wall-clock time worked **outside** the employee’s scheduled shift window for the session’s `work_date`.

Includes:

| Component | Window |
|-----------|--------|
| Pre-shift extra | `[login_at, shift_start)` ∩ `[login_at, effective_end)` |
| Post-shift extra | `[shift_end, logout_at)` ∩ session bounds (same rules as payroll OT) |

**Effective end** = `logout_at` for closed sessions, **`now`** for open sessions (live projection), capped to login `work_date` end-of-day (overnight parity with OT).

### Canonical examples (shift 10:00–18:00)

| Session (wall clock) | Pre | Post | XT |
|----------------------|-----|------|-----|
| 09:30–18:00 | 30m | 0 | **XT30m** |
| 10:00–18:30 | 0 | 30m | **XT30m** |
| 09:30–18:30 | 30m | 30m | **XT1h** |
| 10:00–18:00 | 0 | 0 | *(hidden)* |
| 09:00 login, now 09:45 (open) | 45m | 0 | **XT45m** |

XT uses **session wall-clock bounds**, not `active_duration_seconds`. Idle time inside an outside-shift window still counts (consistent with payroll OT post-shift behaviour).

---

## Existing implementation review

### What attendance already exposes

| Field / API | Pre-shift | Post-shift | Usable for XT? |
|-------------|-----------|------------|----------------|
| `work_sessions.overtime_seconds` | No | Yes (closed sessions only) | **Partial** — post only, not live |
| `workforce_attendance_days.overtime_seconds` | No | Day sum of session OT | **Partial** — no pre, no open session |
| `first_login_at` | Timestamp only | — | Inputs only |
| `minutes_late` | — | — | Unrelated (lateness, not extra time) |
| `WorkingHoursToday` | No | No | — |
| `TeamActivityPresenceMetrics` | No | No | — |

**Conclusion:** No existing field exposes `pre_shift_extra_seconds`. Post-shift math exists privately in `PresenceEngineService::calculateOvertimeSeconds()` and is persisted on close as `overtime_seconds`.

### What Team Activity already has (Late pattern to mirror)

```
WorkforceAttendanceDay.minutes_late
  → WorkingHoursToday.minutesLate
  → TeamActivityPresenceMetrics.minutesLate
  → TeamActivityAgentRow.minutesLate
  → TeamActivityMemberStatusPresenter::lateDurationLabel()
  → live-presence.blade.php  (L + superscript)
```

Late gating: hide for virtual agents, Leave status, and when register kind ≠ Late.

XT follows the same passthrough shape with **`extraTimeLabel`** on the DTO (do **not** reuse `overtimeLabel` — that field is reserved for future payroll OT surfacing).

### Payroll OT must stay separate

| | Payroll OT | XT |
|---|-----------|-----|
| Stored | Yes (`overtime_seconds`) | No |
| Pre-shift | Never | Yes |
| Matrix / payroll / reports | Yes | **No touch** |
| Team Activity label | `OT` (future) | **`XT`** |
| Approval | N/A today | N/A |

---

## Shared calculation — single source

### New read-only service

**`App\Services\Operations\ShiftExtraTimeService`**

Responsibilities:

1. Compute per-session pre/post/total extra seconds (read-only, no DB writes).
2. Sum attributable today sessions for Team Activity.
3. Never duplicate post-shift logic — delegate to `PresenceEngineService`.

### Post-shift reuse (no duplication)

| Session state | Post-shift source |
|---------------|-------------------|
| **Closed** | `(int) $session->overtime_seconds` — already computed at finalize via `calculateOvertimeSeconds()` |
| **Open** | New **public** method on `PresenceEngineService`, e.g. `projectPostShiftExtraSeconds(WorkSession $session, Carbon $effectiveEnd): int` — extract body from existing private `calculateOvertimeSeconds()` |

Extracting the private method into a shared public/post-projection API is a **refactor of existing OT math**, not a new attendance rule. Payroll persistence path unchanged.

### Pre-shift (new, read-only)

```php
/**
 * Wall-clock seconds of this session before expected shift start.
 * Symmetric to post-shift OT; never persisted.
 */
public function preShiftExtraSeconds(
    User $user,
    WorkSession $session,
    Carbon $effectiveEnd,
): int
```

Algorithm (mirror OT caps):

1. Resolve `schedule = workCalendarService->scheduleFor($user, $workDate)`.
2. If no schedule → `0`.
3. `shiftStart = expectedWorkStartAt($schedule, $workDate)`.
4. If `login_at >= shiftStart` → `0`.
5. `preEnd = min(effectiveEnd, shiftStart)`, capped to `work_date` end-of-day if needed.
6. Return `max(0, login_at → preEnd)` in seconds.

### Day total

```php
public function totalExtraSecondsForSessions(
    User $user,
    Collection $sessions,
    Carbon $at,
): int
```

For each **attributable** session on today’s `work_date` (`is_attributable !== false`):

```
pre  = preShiftExtraSeconds(user, session, effectiveEnd(session))
post = session.isOpen()
         ? presenceEngine.projectPostShiftExtraSeconds(session, at)
         : (int) session.overtime_seconds
total += pre + post
```

Multi-session days sum per session; gaps inside the shift are not XT.

### Unit-test contract

Assert the service satisfies:

```
XT_total === pre_shift_extra_seconds + post_shift_extra_seconds
```

For closed session `09:30–18:30` on `10:00–18:00` schedule:

- `pre_shift_extra_seconds = 1800`
- `post_shift_extra_seconds = 1800` (= stored `overtime_seconds`)
- `total = 3600` → label `1h`

---

## Team Activity integration

### Data flow

```
WorkSession[] (today, already loaded by TeamActivityPresenceMetricsService)
  + User (from roster)
  ▼
ShiftExtraTimeService::totalExtraSecondsForSessions()
  ▼
PresenceEngineService::formatDuration()  → compact label ("45m", "1h")
  ▼
TeamActivityPresenceMetrics.extraTimeLabel  (null when 0)
  ▼
TeamActivityAgentRow.extraTimeLabel
  ▼
TeamActivityMemberStatusPresenter::extraTimeDurationLabel()
  ▼
live-presence.blade.php  →  XT⁴⁵ᵐ
```

Compute inside **`TeamActivityPresenceMetricsService`** — it already loads today’s sessions per user; no new queries.

### Display rules

Show XT **only when total > 0**.

| Visual | Example |
|--------|---------|
| Active + extra | `A²ʰ¹⁵ᵐ` + `XT⁴⁵ᵐ` |
| Auto Logged Out + extra | `ALO⁹ʰ` + `XT¹ʰ³⁰ᵐ` |
| Idle + extra | `I³⁰ᵐ` + `XT¹⁵ᵐ` |

Implementation:

- Reuse **compact superscript** styling from Late (`team-activity-operational-indicator` pattern).
- Mark: `XT` (not `OT`).
- Class: `team-activity-operational-indicator--extra-time`.
- Place in `live-presence.blade.php` after the primary pill; Late and XT may both appear (orthogonal signals).

### Presenter gating (`extraTimeDurationLabel`)

Return `null` when:

| Condition | Reason |
|-----------|--------|
| `extraTimeLabel` empty / 0 | Nothing to show |
| Virtual agent (IRA) | No workforce schedule |
| `TeamActivityStatus::Leave` | On approved leave |
| `calendarBadge` is Weekly Off or Holiday | Off-roster day — entire day is “extra” structurally; suppress to avoid misleading XT |
| `TeamActivityStatus::NoSchedule` | No shift boundaries |
| No schedule row for user today | Cannot compute boundaries |

**Show XT when allowed:**

| Status | XT? |
|--------|-----|
| Working / Idle / Break | Yes, if > 0 |
| Auto Logged Out | Yes, if closed sessions contributed XT |
| Off Duty (Shift Ended) | Yes, if day total > 0 |
| Pending / Busy overlays | Yes, if session has XT |
| Half Day + active work | Yes, if outside full shift window |
| Late (L indicator) | Yes — XT and L are independent |

### Aria label

Extend `presenceAriaLabel()`:

```
Active · 2h 15m · Extra Time 45m
```

Append `Extra Time {label}` after Late segment when present.

---

## Legend

Add to `TeamActivityPresenceLegend::entries()`:

| Abbr | Label | Notes |
|------|-------|-------|
| **XT** | Extra Time | Shipped with feature |
| OT | Overtime | Keep `'future' => true` — payroll OT, not XT |

Tooltip copy (legend row):

> **XT — Extra Time**  
> Time worked outside scheduled shift (before and/or after). Operational indicator only.

Optional subtitle in legend template: distinguish XT from future OT entry.

---

## Files to change

| File | Change |
|------|--------|
| `app/Services/Operations/ShiftExtraTimeService.php` | **New** — shared XT calculator |
| `app/Services/Operations/PresenceEngineService.php` | Extract public post-shift projection (refactor only) |
| `app/Services/Dashboard/TeamActivityPresenceMetricsService.php` | Compute + attach `extraTimeLabel` |
| `app/Data/TeamActivityPresenceMetrics.php` | Add `?string $extraTimeLabel` |
| `app/Data/TeamActivityAgentRow.php` | Add `?string $extraTimeLabel` |
| `app/Services/Dashboard/TeamActivityPanelService.php` | Pass `extraTimeLabel` from metrics |
| `app/Services/Dashboard/TeamActivityIraMemberBuilder.php` | `extraTimeLabel: null` |
| `app/Support/Dashboard/TeamActivityMemberStatusPresenter.php` | `extraTimeDurationLabel()`, aria |
| `resources/views/components/team-activity/live-presence.blade.php` | `extraTime` prop + XT block |
| `resources/views/dashboard/partials/team-activity-agent-row.blade.php` | Wire presenter → component |
| `resources/css/app.css` | `.team-activity-operational-indicator--extra-time` |
| `app/Support/Dashboard/TeamActivityPresenceLegend.php` | XT entry |

### Explicitly out of scope

- Migrations / database columns  
- `AttendanceDayCalculator`, `AttendanceRegisterService`  
- Attendance Matrix, payroll, workforce reports  
- `overtime_seconds` write path  
- Leave rules, half-day register logic  
- Renaming payroll OT anywhere  

---

## Scenario verification

| Scenario | Schedule | Session | Expected XT |
|----------|----------|---------|-------------|
| **Early work** | 10–18 | 09:30–now (10:30) | **30m** |
| **Late work (post-shift)** | 10–18 | 10:00–18:30 closed | **30m** |
| **Both** | 10–18 | 09:30–18:30 closed | **1h** |
| **On-time, in shift** | 10–18 | 10:00–18:00 | **hidden** |
| **Leave** | any | — | **hidden** (LV status gate) |
| **Half Day** | 10–18 | 09:00–12:00 working | **1h** pre-shift if logged early |
| **Holiday** | 10–18 | worked (Extra) | **hidden** (calendar badge gate) |
| **Weekly Off** | 10–18 | worked (Extra) | **hidden** (calendar badge gate) |
| **Auto Logged Out** | 10–18 | 09:00–18:30 ALO | **XT1h30m** (1h pre + 30m post) if 9h session spans both |
| **No Schedule** | none | any | **hidden** |
| **Pending overlay** | 10–18 | 09:30 open + P overlay | **XT30m** still shown |
| **Late login + early arrival N/A** | 10–18 | 10:20–18:00 | **hidden** (login inside shift, no post) |
| **Open post-shift** | 10–18 | 10:00–now (18:30) | **30m** live projection |

### Anti-patterns (must not happen)

- XT on Leave rows  
- XT on Holiday / Weekly Off badge rows  
- XT when no schedule  
- XT labeled `OT`  
- XT mutating `overtime_seconds` or attendance days  
- XT differing from `overtime_seconds` on post-shift for **closed** sessions  

---

## Tests

### New unit tests — `tests/Unit/Operations/ShiftExtraTimeServiceTest.php`

| Test | Assert |
|------|--------|
| `test_pre_shift_only` | 09:30–18:00 → 1800s pre, 0 post |
| `test_post_shift_closed_reuses_stored_overtime` | Closed session uses `overtime_seconds` |
| `test_post_shift_open_projects_live` | Open session past shift end uses `now` |
| `test_combined_pre_and_post` | 09:30–18:30 → 3600s total |
| `test_no_schedule_returns_zero` | No schedule → 0 |
| `test_login_after_shift_start_no_pre` | 10:15 login → 0 pre |
| `test_multi_session_sums` | Two sessions with 30m pre each → 3600s |
| `test_total_equals_pre_plus_post` | Identity on all fixtures |

### New unit tests — presenter

`tests/Unit/Dashboard/TeamActivityMemberStatusPresenterTest.php`:

- XT label returned when `extraTimeLabel` set  
- Hidden for Leave, NoSchedule, virtual  
- Aria includes “Extra Time”  

### New feature tests — UI

`tests/Feature/DashboardTeamActivityUiTest.php` (mirror late-indicator tests):

- Early login renders `team-activity-operational-indicator--extra-time` + `XT` mark  
- On-time in-shift: no XT markup  
- Leave / holiday / weekly off: no XT markup  
- ALO row with extra time shows XT  

### Regression guard

```bash
php artisan test --filter='ShiftExtraTimeServiceTest|TeamActivityMemberStatusPresenterTest|DashboardTeamActivityUiTest|TeamPerformanceIntelligenceTest|MonthlyAttendanceMatrixTest|AttendanceDayCalculatorTest'
```

**Existing attendance/payroll tests must remain unchanged** — no assertions on new fields in register/matrix.

---

## Rollback

Presentation-only feature:

1. Revert Blade / CSS / presenter / metrics passthrough / legend entry.  
2. Delete `ShiftExtraTimeService` and optional `PresenceEngineService` public extract.  
3. No migrations to roll back.  
4. Single deploy revert restores prior UI.

---

## Implementation order

1. Extract `projectPostShiftExtraSeconds()` on `PresenceEngineService` (behaviour-preserving refactor).  
2. Add `ShiftExtraTimeService` + unit tests (green before UI).  
3. Thread `extraTimeLabel` through metrics → DTO → panel.  
4. Presenter + Blade + CSS.  
5. Legend entry.  
6. Feature UI tests.  
7. Full regression filter above.

---

## Related documents

- [team-activity-overtime-investigation.md](./team-activity-overtime-investigation.md) — why payroll OT ≠ early login; OT UI still future  
- [team-activity-late-indicator-investigation.md](./team-activity-late-indicator-investigation.md) — passthrough pattern XT mirrors  
- [team-activity-presence-column-investigation.md](./team-activity-presence-column-investigation.md) — operational indicator slot architecture  

---

## Code references (current)

| Concern | Location |
|---------|----------|
| Post-shift OT (private) | `PresenceEngineService::calculateOvertimeSeconds()` |
| OT persisted on close | `PresenceEngineService::finalizeSession()` |
| Late passthrough | `WorkingHoursTodayService` → `TeamActivityPresenceMetricsService` |
| Late UI | `live-presence.blade.php`, `TeamActivityMemberStatusPresenter::lateDurationLabel()` |
| Session loader for metrics | `TeamActivityPresenceMetricsService::forUsers()` |
| Shift boundaries | `WorkCalendarService::expectedWorkStartAt()` / `expectedWorkEndAt()` |
