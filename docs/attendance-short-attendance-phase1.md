# Attendance Policy Phase 1 — Short Attendance Handling

Phase 1 prevents employees from becoming **Present** after a brief login with little effective work. Attendance for closed working days is finalized from **effective worked time** (`active_duration_seconds`), not from login/logout alone.

Future phases (not implemented here): manager approval, HR override, attendance exceptions, grace rules, work-from-home exceptions.

---

## Business rules

| Worked time (today) | Outcome |
|---------------------|---------|
| `0` minutes | **Absent** (register: `not_started`) |
| `> 0` and `< short_attendance_minutes` (default 30) | **Short Attendance** (register: `short_attendance`) — must **not** become Present |
| `≥ short_attendance_minutes` | Existing Present / Late / Completed / OnTime logic unchanged |
| Approved leave | Leave continues to override attendance (unchanged) |
| Holiday / week off | Unchanged |
| Half-day leave | Existing half-day leave logic unchanged |
| Open session (still logged in) | Remains `active` / `away` — short attendance applies after the session closes |

**Worked time** = `floor(active_duration_seconds / 60)` from attributable sessions for the work date.

**Auto logout** is only a session end event (`away_timeout`). It never marks Present by itself. After close, status is finalized from worked minutes (so a short auto-logout day becomes Short Attendance or Absent).

**Payroll:** Short Attendance is treated as Absent (non-payable full day). No special salary calculation in Phase 1.

**Audit:** When status is Short Attendance, persist `status_reason = short_attendance` and publish `worked_minutes` on the attendance recorded event.

---

## Decision table

| Condition | Register status | Matrix cell | Payroll |
|-----------|-----------------|-------------|---------|
| No sessions, working day after shift start | `not_started` | Absent (`A`) | Absent |
| Closed sessions, worked = 0 | `not_started` | Absent (`A`) | Absent |
| Closed sessions, 0 < worked < threshold | `short_attendance` | Short Attendance (`SA`) | Absent |
| Closed sessions, worked ≥ threshold, on-time | `completed` / etc. | Present (`P`) | Present |
| Closed sessions, worked ≥ threshold, late login | `late` | Late (`L`) | Present (late) |
| Open session | `active` / `away` | Present or Late (login flag) | n/a until close |
| Approved full-day leave | `on_leave` | Leave (`V`) | Leave rules |
| Approved half-day leave | `half_day` | Half Day (`H`) | Half day rules |
| Holiday / weekly off (no work) | `scheduled_off` | Holiday / Weekly Off | Unchanged |
| Work on off day | `extra` | Extra (`E`) | Unchanged |

---

## Configuration

| Key | Env | Default |
|-----|-----|---------|
| `workforce_calendar.short_attendance_minutes` | `ATTENDANCE_SHORT_ATTENDANCE_MINUTES` | `30` |

No hardcoded threshold in calculator logic — value is read from config.

Example:

```env
ATTENDANCE_SHORT_ATTENDANCE_MINUTES=30
```

---

## Files changed

### Core
- `app/Services/Operations/AttendanceDayCalculator.php` — short attendance gate on closed working days
- `app/Data/Operations/AttendanceDayResult.php` — `statusReason`
- `app/Models/WorkforceAttendanceDay.php` — `status_reason`
- `app/Services/Operations/AttendanceRegisterService.php` — audit fields on `AttendanceRecorded` payload
- `database/migrations/2026_08_06_103000_add_status_reason_to_workforce_attendance_days_table.php`

### Enums / mapping / payroll
- `app/Enums/AttendanceDayStatus.php` — `ShortAttendance`
- `app/Enums/AttendanceMatrixCellKind.php` — `ShortAttendance` (`SA`, tone `short`, payable `0`)
- `app/Support/Workforce/AttendanceMatrixCellMapper.php`
- `app/Services/Workforce/MonthlyAttendanceMatrixService.php` — SA counts toward absent totals
- `app/Services/Workforce/Payroll/PayrollPayableDayPolicy.php` — SA ≡ Absent
- `app/Services/Workforce/WorkforceMember360Service.php` — trend series
- `app/Services/Operations/JulyAttendancePayrollRepairService.php` — status→kind map

### Config / UI
- `config/workforce_calendar.php`
- `.env.example`
- `resources/views/workforce-management/attendance/index.blade.php` — legend
- `resources/css/app.css` — `.attendance-matrix-badge--short`

### Tests
- `tests/Unit/Operations/AttendanceDayCalculatorTest.php`
- `tests/Unit/Workforce/AttendanceMatrixCellMapperTest.php`
- `tests/Feature/Workforce/PayrollPhase1Test.php`
- Related attendance/register/contribution tests updated so closed sessions seed realistic `active_duration_seconds`

---

## Test results

Targeted verification (Phase 1 checklist):

| Case | Expected | Covered by |
|------|----------|------------|
| 0 min → Absent | `not_started` | `test_zero_worked_minutes_with_closed_session_is_absent` |
| 5 / 18 / 29 min → Short Attendance | `short_attendance` + reason | `test_short_attendance_for_worked_minutes_below_threshold` |
| 30 min → existing rules | `completed` when on-time | `test_exactly_threshold_minutes_follows_existing_present_logic` |
| Half Day leave unchanged | leave override | existing `HalfDayLeaveAttendanceTest` + leave override unit test |
| Present / Late unchanged (≥ threshold) | existing statuses | calculator completed/late tests |
| Payroll SA = Absent | non-payable, absent count | `test_short_attendance_is_payroll_absent` |
| Auto logout alone ≠ Present | short active + `away_timeout` → SA | `test_auto_logout_with_short_active_time_is_not_present` |
| Open session not forced to SA | stays `active` | `test_open_session_remains_active_even_with_short_worked_time` |

Verified locally (2026-08-06): core Phase 1 suite green — `AttendanceDayCalculatorTest`, `AttendanceMatrixCellMapperTest`, short-attendance payroll case, register/contribution/half-day leave coverage (47 related tests passed in targeted run).

```bash
php artisan test --filter='AttendanceDayCalculatorTest|AttendanceMatrixCellMapperTest|PayrollPhase1Test|AttendanceRegisterTest|ContributionEngineTest|HalfDayLeaveAttendanceTest'
```

---

## Future enhancement ideas (out of scope for Phase 1)

- Manager approval / acknowledge short attendance
- HR override to Present / Half Day with audit trail
- Attendance exception requests (WFH, field work, training)
- Grace rules (e.g. first N minutes, once per month)
- Distinct payroll fraction later (if product wants partial pay)
- Separate monthly summary counter for Short Attendance (today rolls into Absent totals for payroll safety)
- Duration-based half-day threshold (not leave-based) — only if product defines it
