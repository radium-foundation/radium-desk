# Team Activity Late Indicator — Investigation

**Prompt:** P[04-08]-016  
**Date:** 2026-08-04  
**Type:** Root cause + UI-only surfacing (no attendance / presence / payroll math changes)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-late-indicator-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-late-indicator-investigation.canvas.tsx)

---

## Bottom line

Attendance already stores lateness. Team Activity never reads or renders it. Late employees correctly show Active/Idle for live presence, with no orthogonal Late badge.

| Signal | Result |
|--------|--------|
| Register late fields | Stored |
| Team Activity late field | Missing |
| Recommended fix scope | UI-only |

---

## Example gap

| Person / case | Attendance | Team Activity today | Gap |
|---------------|------------|---------------------|-----|
| Jayram | Late in Attendance Register | Active / Idle only | Missing L^{n}m |
| Shubhanshi | Late in Attendance Register | Active / Idle only | Missing L^{n}m |
| On-time agent | Present / on_time_login=true | Active · 37m | Correct — no L |
| On Leave | Leave cell / On Leave status | On Leave · Annual Leave | Must stay unchanged |
| Weekly Off / Holiday | Calendar badge | Offline + badge | Must stay unchanged |

---

## Why this is intentional for status

Attendance Late is a day-level punctuality classification. Team Activity status is current operational presence (Active / Idle / Break / On Leave / …). Replacing Active with Late would hide live state. The fix is a compact late indicator beside presence — not a new `TeamActivityStatus`.

---

## Pipeline — where late is dropped

| Layer | Role | Late handling |
|-------|------|---------------|
| Attendance Register | Persists `on_time_login` + `minutes_late` on `workforce_attendance_days` | Shows L / Late |
| AttendanceMatrixCellMapper | Maps Late status or `on_time_login=false` → Late cell | Source of truth for Late kind |
| WorkingHoursTodayService | Reads attendance day for Today active hours | Ignores late fields |
| TeamActivityPresenceMetricsService | Builds Today / Current / Sessions metrics | No late field |
| TeamActivityAgentRow | DTO for panel rows | No `minutesLate` |
| TeamActivityStatusResolver | Resolves Active / Idle / Leave / … | No Late branch (by design) |
| member-status Blade | Renders Status · duration | No L superscript |

### Structural gaps

**Has late data**

- `workforce_attendance_days.on_time_login`
- `workforce_attendance_days.minutes_late`
- `AttendanceMatrixCellMapper` Late kind
- Presence snapshot `on_time_login` (unused by panel)

**Team Activity lacks**

- No Late in `TeamActivityStatus` enum
- No `minutesLate` on `TeamActivityAgentRow`
- Status resolver never reads attendance day
- `member-status` has no L superscript slot

---

## Fix

**Constraint:** Do not modify attendance calculations, Team Activity status logic, presence calculations, or payroll. Only surface existing register information in the Team Activity UI.

| # | Step | Detail |
|---|------|--------|
| 1 | Passthrough | Carry register `on_time_login` + `minutes_late` through `WorkingHoursToday` → presence metrics → `TeamActivityAgentRow` |
| 2 | Reuse mapper | Use `AttendanceMatrixCellMapper::kindFor` (same Late rule as the register UI) |
| 3 | UI only | Render compact `L^{minutes}m` beside live presence — do not change status resolver, attendance math, presence, or payroll |
| 4 | Format | `Active · L³³ᵐ · 37m` / `Idle · L⁸ᵐ · 15m` |

### Target display

- `Active · L³³ᵐ · 37m`
- `Idle · L⁸ᵐ · 15m`

L = Late · superscript = total minutes late today · final duration = current active/idle duration

### Touched files

| File / type | Change |
|-------------|--------|
| WorkingHoursToday | Passthrough late fields from attendance day |
| TeamActivityPresenceMetrics | Expose `minutesLate` when register kind is Late |
| TeamActivityAgentRow | Optional `minutesLate` |
| TeamActivityMemberStatusPresenter | Late segment + aria label |
| member-status.blade.php | Render L + superscript duration |
| AttendanceMatrixCellMapper | Reuse `kindFor` for Late detection |

---

## Verification

| Case | Setup | Expect |
|------|-------|--------|
| Late employee | Register Late + open session | HTML contains late indicator with minutes |
| Non-late employee | `on_time_login=true` | No late indicator |
| Leave | Approved leave | On Leave + reason; no L |
| Holiday / Weekly Off | Calendar off day | Badge/status unchanged; no L |
| Preserved behaviour | Existing Active/Idle/duration/KPI assertions | Still pass |

**Regression:** Existing Team Activity presence, leave, weekly-off, and KPI UI tests must continue to pass unchanged in behaviour.
