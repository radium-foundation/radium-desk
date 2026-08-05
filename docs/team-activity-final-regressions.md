# Team Activity — Final Regressions

**Date:** 2026-08-05  
**Scope:** Remaining `DashboardTeamActivity*` failures after query-budget hardening  
**Related:** [team-activity-query-budget-final.md](./team-activity-query-budget-final.md)  
**Constraints:** No Canvas. Budget remains `<= 120`.

---

## Verdict

**Production Ready**

| Suite | Result |
|-------|--------|
| `DashboardTeamActivity*` | **77 / 77 pass** |
| `TeamActivityMemberStatusPresenterTest` | **pass** (incl. shift-start aria) |
| Query budget (`test_panel_build_query_count_stays_bounded`) | **pass** (`<= 120`, unchanged) |

---

## Failures investigated

### 1. IRA expanded HTML contains `Logged In`

| Question | Finding |
|----------|---------|
| Bug or stale expectation? | **Stale expectation** (false positive) |
| IRA inherits human attendance? | **No** |

**Root cause:** Compact presence legend (panel chrome) includes **Not Logged In** and **Auto Logged Out**.  
`assertStringNotContainsString('Logged In', $html)` matched the substring inside **Not Logged In** on the whole panel, not on the IRA row.

**IRA row itself** (from the failing HTML): virtual row, IRA automation presence (`B` / Busy or Idle), KPI history — no login/logout/shift-start attendance copy.

**Fix:** Scope attendance-label assertions to the IRA `<li data-team-activity-agent="…">` only, and strengthen with explicit denies for `Not Logged In`, `Auto Logged Out`, and `Shift Not Started` on that row.

**Product rule maintained:** IRA must never inherit human attendance labels.

---

### 2. PresenceV2 → `AutoLogout` instead of `Working` / `Idle`

| Question | Finding |
|----------|---------|
| Is `AutoLogout` correct here? | **No** |
| Contradiction? | **Yes** — Latest Activity `Reassigned` while Current Status `AutoLogout` |

**Fixture intent:** Away-timeout close → re-login → business audit (`service_case.reassigned`) → status must be `Working` or `Idle`.

**Root cause (bug):** `PresenceEngineService` request-scoped `openSessionCache` survived a direct DB close of the open session. The second `startSession()` returned the stale cached row and **did not create** a new open session. Panel build then saw:

1. `session_open = false` (today’s session closed in DB)
2. `last_ended_reason = AwayTimeout` → `TeamActivityStatus::AutoLogout`
3. Latest allowlisted audit still `Reassigned`

**Fix:** In `startSession()`, refresh the cached open session; if `logout_at` is set, forget the cache and re-query before creating a new session. No compatibility shim; no weakened assertion.

**Product rule maintained:** Latest Activity must never contradict Current Status when an open session exists after re-login.

---

### 3. `Shift starts 9:00 AM` missing from HTML

| Question | Finding |
|----------|---------|
| Wording intentionally changed? | **No** — resolver still emits `Shift starts {time}` |
| Time formatting changed? | **No** — still `g:i A` via overview `formatShiftClock` |
| Presenter / Blade changed? | **Yes** — compact codes dropped secondary shift context from aria/title |

**Product standard (kept):**

| Layer | Standard |
|-------|----------|
| Visual code | `SNS` |
| Status label | `Shift Not Started` |
| Accessible / tooltip context | `Shift starts 9:00 AM` |

Same pattern as Leave (`LV` + reason in aria/title). Compact codes stay visual-only; shift metadata stays in `workingLabel` and is surfaced via presenter.

**Fix:**

- `TeamActivityMemberStatusPresenter::usesWorkingLabelContext()` / `presenceTitle()` for Leave, NotStartedShift, OffDuty, Logout
- Blade uses `presenceTitle()` instead of Leave-only title wiring
- Unit coverage for NotStartedShift aria + title

No dual wording paths; no “Shift starts” vs alternate copy hacks.

---

## Files touched

| File | Change |
|------|--------|
| `app/Services/Operations/PresenceEngineService.php` | Revalidate stale open-session cache in `startSession()` |
| `app/Support/Dashboard/TeamActivityMemberStatusPresenter.php` | Shift/leave working-label context + `presenceTitle()` |
| `resources/views/dashboard/partials/team-activity-agent-row.blade.php` | Use presenter `presenceTitle()` |
| `tests/Feature/DashboardTeamActivityExpandTest.php` | IRA-row-scoped attendance asserts (stronger) |
| `tests/Unit/Dashboard/TeamActivityMemberStatusPresenterTest.php` | Shift-starts aria/title |

---

## Query budget

| Guard | Value |
|-------|------:|
| Budget | **120** (unchanged) |
| Suite | Pass |

No budget raise. No new N+1 paths introduced by these presentation / session-start fixes.

---

## Tests run

```bash
php artisan test --filter=DashboardTeamActivity
php artisan test --filter='TeamActivityMemberStatusPresenterTest|DashboardTeamActivitySortingAndIraTest::test_panel_build_query_count_stays_bounded'
```

---

*End of report.*
