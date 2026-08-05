# Team Activity Overtime Indicator — Investigation

**Date:** 2026-08-05  
**Type:** Read-only investigation (no code changes)  
**Case:** Employees who log in before their scheduled shift show `A¹ʰ²⁰ᵐ` in Team Activity with no OT indicator, while Attendance Register correctly reflects the early login.

---

## Bottom line

Team Activity does **not** show an OT indicator because **overtime surfacing was never implemented** — the UI slot, DTO field, and legend entry exist only as placeholders. This is separate from a **semantic mismatch**: the example expectation (`09:15` login, `10:00` shift → `OT⁴⁵ᵐ`) treats *pre-shift* minutes as overtime, but the platform defines **overtime exclusively as post-shift-end wall-clock time** (payroll / attendance register). Early login is classified as **on-time** (`on_time_login = true`), not OT.

| Question | Answer |
|----------|--------|
| Is OT calculated anywhere? | Yes — on session **close**, stored on `work_sessions.overtime_seconds`, rolled up to `workforce_attendance_days.overtime_seconds` |
| Is early-login time counted as OT? | **No** — anywhere in attendance, payroll, or presence |
| Is OT shown in Team Activity? | **No** — `overtimeLabel` is always `null`; Blade has no OT operational indicator |
| Is OT approval-based? | **No** — purely schedule-derived |
| Is OT end-of-day only? | **Computed at logout**; open sessions always have `overtime_seconds = 0` until closed |
| Should Avinash show `OT⁴⁵ᵐ` for 09:00 login / 10:00 shift? | **Not under current OT definition**; early login is already visible via register + session duration superscript |

---

## Example — Avinash

| Field | Value |
|-------|-------|
| Shift start | 10:00 AM |
| Presence | Active since 09:00 / 09:30 |
| Attendance | Correctly shows early login (`first_login_at`, Present / Active) |
| Team Activity | `A¹ʰ²⁰ᵐ` (current open-session duration superscript) |
| OT indicator | Absent |

**Why `A¹ʰ²⁰ᵐ` appears:** `TeamActivityMemberStatusPresenter::stateDurationLabel()` renders the **elapsed time since login** for Working/Idle/Break (`currentDurationLabel` from open session). At ~10:20, a 09:00 login yields ~1h 20m. This is session duration, not overtime.

**Why no OT appears:** Three independent reasons stack:

1. **UI not built** — no OT passthrough or Blade slot (Late was implemented; OT was deferred as “future”).
2. **Early login ≠ OT in data model** — `on_time_login = true`, `minutes_late = null`, `overtime_seconds = 0`.
3. **Open session** — even post-shift OT would be `0` until logout (`calculateOvertimeSeconds` requires `logout_at`).

---

## Expected behaviour — product vs platform

### User example (pre-shift framing)

| Login | Shift start | Expected Team Activity |
|-------|-------------|------------------------|
| 09:15 | 10:00 | `OT⁴⁵ᵐ` |
| 10:20 | 10:00 | No OT |

This treats **minutes before shift start** as “earned overtime.”

### Platform definition (post-shift framing)

Overtime is documented and tested as **wall-clock seconds after expected shift end**:

```612:646:app/Services/Operations/PresenceEngineService.php
    private function calculateOvertimeSeconds(?User $user, WorkSession $session, Carbon $logoutAt): int
    {
        // ...
        $overtimeStart = $session->login_at->greaterThan($expectedEnd)
            ? $session->login_at->copy()
            : $expectedEnd->copy();

        if ($effectiveLogout->lte($overtimeStart)) {
            return 0;
        }

        return max(0, (int) $overtimeStart->diffInSeconds($effectiveLogout));
    }
```

Confirmed by test: 09:00–18:10 session on 09:00–18:00 schedule → **600 s (10 min) OT**, not early-login minutes.

| Login | Shift | Platform OT while mid-shift | Platform OT after 18:10 logout |
|-------|-------|------------------------------|--------------------------------|
| 09:15 | 10:00 | 0 | 0 (unless also past shift end) |
| 10:20 | 10:00 | 0 | Depends on logout vs shift end |

**Verdict:** Under the **existing attendance OT definition**, Team Activity should **not** show OT for early login. It **should** eventually show OT when `workforce_attendance_days.overtime_seconds > 0` (post-shift earned time), subject to leave/holiday gating below.

**Design note:** P[04-08]-017 planned **Early Login** and **Overtime** as *separate* future operational indicators. Conflating early login with `OT` would diverge from attendance matrix / Member 360 / payroll OT columns, which all use post-shift `overtime_seconds`.

---

## Pipeline trace

```
Attendance Register (workforce_attendance_days)
  │  first_login_at, on_time_login, minutes_late, overtime_seconds, status
  ▼
WorkingHoursTodayService
  │  active_duration_seconds, session_count, minutesLate (late passthrough only)
  │  ❌ no overtime passthrough
  ▼
TeamActivityPresenceMetricsService
  │  todayDurationLabel, currentDurationLabel, minutesLate
  │  ❌ no overtime field
  ▼
TeamActivityPanelService → TeamActivityAgentRow
  │  overtimeLabel: null  (hardcoded)
  ▼
TeamActivityMemberStatusPresenter
  │  stateDurationLabel() → A{duration} superscript
  │  lateDurationLabel() → L{minutes} (implemented)
  │  ❌ no overtimeDurationLabel()
  ▼
live-presence.blade.php
  │  Renders code + duration superscript + optional Late indicator
  │  ❌ no OT operational indicator slot
```

### Parallel path (unused for compact UI)

`TeamAvailabilityOverviewService` embeds `PresenceEngineService::snapshotFor()`, which includes `overtime_duration` from the **open session’s** `overtime_seconds` (always `0` while open). `TeamActivityStatusResolver::formatOvertimeSuffix()` can append `(+{duration} OT)` to `workingLabel`, but:

- `workingLabel()` returns **`null`** for Working / Idle / Break (durations moved to Presence column in P[04-08]-017).
- Compact UI never reads this suffix.

---

## Layer-by-layer detail

### 1. Attendance Register

| Field | Early login (09:00, shift 10:00) | Post-shift OT (logged past 18:00) |
|-------|----------------------------------|-------------------------------------|
| `first_login_at` | 09:00 | First session login |
| `on_time_login` | `true` (login ≤ shift start) | `true` / `false` per lateness |
| `minutes_late` | `null` | Set only when Late |
| `overtime_seconds` | `0` until post-shift work is **closed** | Sum of closed session OT |
| Matrix tooltip | Shows login time; OT line only if `overtime_seconds > 0` | `OT 10m` etc. |

Early login is “correct” in attendance via **punctuality** (`on_time_login`, `first_login_at`, active duration) — not via OT.

### 2. Working Hours Today

`WorkingHoursTodayService` reads the attendance day for active hours and late minutes. It does **not** expose `overtime_seconds`.

### 3. Presence metrics

`TeamActivityPresenceMetricsService` builds Today / Current / Sessions from sessions + `WorkingHoursToday`. No OT computation or passthrough.

### 4. Team Activity DTO

`TeamActivityAgentRow` defines `overtimeLabel` but both builders hardcode it:

```117:124:app/Services/Dashboard/TeamActivityPanelService.php
            $agents[] = new TeamActivityAgentRow(
                // ...
                workingLabel: $this->statusResolver->workingLabel($member, $status),
                overtimeLabel: null,
```

Same in `TeamActivityIraMemberBuilder`.

### 5. Presenter

`TeamActivityMemberStatusPresenter` mirrors the Late pattern for `minutesLate` but has **no OT equivalent**. `stateDurationLabel()` drives the `A¹ʰ²⁰ᵐ` superscript from **session elapsed time**, not OT.

### 6. Blade / CSS

- `live-presence.blade.php`: Late indicator (`L` + superscript) implemented; **no OT block**.
- CSS: `.team-activity-operational-indicator--late` exists; **no `--ot` variant**.
- Legend: `TeamActivityPresenceLegend` lists `OT` with `'future' => true`.

---

## Overtime properties

| Property | Detail |
|----------|--------|
| **Formula** | `max(0, effective_logout − max(login_at, shift_end))`, capped to login `work_date` end-of-day |
| **When written** | Session finalize (`closeSession` / `finalizeSession`) |
| **Open sessions** | `overtime_seconds = 0`; live projection not stored |
| **Day rollup** | Sum of attributable closed sessions’ `overtime_seconds` |
| **Approval** | None — no overtime request workflow |
| **Payroll coupling** | Same field used in matrix summary, Member 360, team performance cards |
| **Pre-shift skip** | Register may return `null` day row before shift if no sessions (`allowPreShiftSkip`); once logged in, day is materialized with OT still 0 |

---

## Scenario verification

| Scenario | Register OT | Team Activity OT today | Should show OT? (platform OT) | Notes |
|----------|-------------|--------------------------|-------------------------------|-------|
| **Early login** (09:00, shift 10:00, mid-morning) | 0 | No | **No** | On-time; duration in `A{time}` superscript |
| **Late login** (10:20, shift 10:00) | 0 | No | **No** | Late shown via `L{minutes}` when register kind = Late |
| **Normal login** (on time, within shift) | 0 | No | **No** | — |
| **Post-shift work** (past shift end, **still logged in**) | 0 (open) | No | **No until logout** | Live OT not persisted |
| **Post-shift work** (past shift end, **logged out**) | > 0 | No | **Yes** (once UI built) | e.g. 10 min after 18:00 |
| **Auto Logged Out** after post-shift | > 0 if past end | No | **Yes** (once UI built) | Same OT rules on close |
| **Idle** (open session) | 0 | No | Same as Active | Status `I`, no OT |
| **Pending** (audit overlay) | 0 | No | **No** | Busy/P overlay |
| **Leave** | 0 / N/A | No | **No** | `TeamActivityStatus::Leave`; block OT |
| **Half Day** leave + work | 0 unless post-shift | No | **No on leave**; OT only if post-shift work on working half | Leave portion never OT |
| **Weekly Off** + work | May have post-shift OT on Extra day | No | **No** per requirement | Calendar badge; suppress OT in Team Activity |
| **Holiday** + work | Extra day; OT if post-shift | No | **No** per requirement | Same suppression |

**Leave / holiday rule:** Attendance may store OT on Extra days (weekly off / holiday work past shift end). Team Activity should **suppress** the OT indicator when `TeamActivityStatus::Leave` or calendar badge is Weekly Off / Holiday — mirroring Late indicator gating in `lateDurationLabel()`.

---

## Root cause summary

| # | Cause | Impact |
|---|-------|--------|
| 1 | **OT UI never shipped** | `overtimeLabel` always null; no Blade/CSS slot; legend marked future |
| 2 | **OT semantics ≠ early login** | User example expects pre-shift minutes; platform OT is post-shift only |
| 3 | **Open-session OT lag** | Even post-shift OT invisible until session closes |
| 4 | **Dead code path** | `formatOvertimeSuffix()` / `presence.overtime_duration` unused in compact Presence column |

The Avinash case is **working as coded**: early login is reflected in attendance and session duration; OT indicator absence is expected given (1)–(2).

---

## Recommendation — smallest presentation-only change

**Prerequisite:** Confirm product intent — **OT in Team Activity must match attendance register OT** (post-shift), not pre-shift “extra effort.” If pre-shift visibility is required, use a separate **Early Login (`EL`)** indicator (per P[04-08]-017 design), not the `OT` label.

### If surfacing register OT (recommended — reuses existing math)

Mirror the Late (P[04-08]-016) passthrough pattern:

| Step | Change |
|------|--------|
| 1 | Add `overtimeSeconds` / `overtimeLabel` to `WorkingHoursToday` from `WorkforceAttendanceDay.overtime_seconds` |
| 2 | Pass through `TeamActivityPresenceMetrics` → `TeamActivityAgentRow.overtimeLabel` |
| 3 | Add `TeamActivityMemberStatusPresenter::overtimeDurationLabel()` — return label only when `overtime_seconds > 0` |
| 4 | Extend `live-presence.blade.php` with `OT` operational indicator (parallel to `L`) |
| 5 | **Gate:** hide when status is Leave, or `calendarBadge` is Weekly Off / Holiday |
| 6 | **Do not** change `PresenceEngineService`, `AttendanceDayCalculator`, or payroll |

**Limitation:** OT appears only after at least one closed session contributes post-shift seconds. For **live** post-shift visibility without mutating attendance, optionally add a **read-only projection** in the presenter:

```
if open session && now > shift_end:
  projected_ot = now - max(login_at, shift_end)  // display only, not persisted
else:
  use register overtime_seconds
```

This projection is presentation-only and should not write to sessions or attendance days.

### What not to do

- Do **not** map early login minutes to `OT` using `overtime_seconds` — field will always be 0 and would mislabel payroll OT elsewhere.
- Do **not** duplicate `calculateOvertimeSeconds` into Team Activity services — read register rollup or call existing formatter on stored seconds.
- Do **not** show OT on Leave / Holiday / Weekly Off rows in Team Activity even if register OT > 0 on Extra days (unless product explicitly overrides).

### Files touched (estimated)

| File | Change |
|------|--------|
| `WorkingHoursToday.php` | Optional overtime fields |
| `WorkingHoursTodayService.php` | Passthrough from attendance day |
| `TeamActivityPresenceMetrics.php` | Optional `overtimeLabel` |
| `TeamActivityPresenceMetricsService.php` | Populate label |
| `TeamActivityPanelService.php` | Set `overtimeLabel` from metrics |
| `TeamActivityMemberStatusPresenter.php` | `overtimeDurationLabel()` + aria |
| `live-presence.blade.php` | OT indicator block |
| `resources/css/app.css` | `.team-activity-operational-indicator--ot` |
| `TeamActivityPresenceLegend.php` | Remove `future` flag from OT when shipped |
| Tests | Mirror `DashboardTeamActivityUiTest` late-indicator cases for OT |

**Out of scope:** Attendance calculations, presence engine, status resolver, payroll, workforce matrix, team performance services.

---

## Verification plan (post-implementation)

| Case | Setup | Expect |
|------|-------|--------|
| Early login, mid-shift | 09:00 login, 10:00 shift, now 10:30 | `A³⁰ᵐ` or similar; **no OT** |
| Late login | 10:20 login, 10:00 shift | `L²⁰ᵐ`; **no OT** |
| Post-shift, closed | Logout 18:15, shift ends 18:00 | **OT¹⁵ᵐ** (after UI built) |
| Post-shift, open | Still logged in at 18:30 | OT only if live projection added; else none |
| Leave | Approved leave | LV; **no OT** |
| Holiday / Weekly Off | Badge + working | **no OT** indicator |
| On-time full day | No post-shift work | **no OT** |

---

## Related docs

- [team-activity-late-indicator-investigation.md](./team-activity-late-indicator-investigation.md) — pattern to mirror for OT passthrough
- [team-activity-presence-column-investigation.md](./team-activity-presence-column-investigation.md) — planned OT / Early Login slots (future)
- [team-activity-presence-legend-investigation.md](./team-activity-presence-legend-investigation.md) — OT legend entry marked future

---

## References (code)

| Component | Path |
|-----------|------|
| OT calculation | `app/Services/Operations/PresenceEngineService.php` |
| Day rollup | `app/Services/Operations/AttendanceDayCalculator.php` |
| Working hours reader | `app/Services/Operations/WorkingHoursTodayService.php` |
| Team Activity metrics | `app/Services/Dashboard/TeamActivityPresenceMetricsService.php` |
| Panel builder | `app/Services/Dashboard/TeamActivityPanelService.php` |
| Duration / Late presenter | `app/Support/Dashboard/TeamActivityMemberStatusPresenter.php` |
| Presence Blade | `resources/views/components/team-activity/live-presence.blade.php` |
| OT test | `tests/Feature/TeamPerformanceIntelligenceTest.php::test_overtime_calculated_correctly` |
