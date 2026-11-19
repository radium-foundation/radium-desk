# P-19-16 — Leave policy deployment-gate audit

| Field | Value |
|---|---|
| Global ID | P-19-16 |
| Cursor ledger ID | **RadiumDesk-P-19-16** |
| Risk | **HIGH** |
| Audit start | 2026-11-19 09:13:43 IST |
| Audit end | 2026-11-19 09:16:45 IST |
| Baseline | `origin/main` @ **`711173a4c`** (PR **#350**, tag **`v4.1.95`**) |
| Fix branch | `fix/p19-16-leave-policy-audit` (worktree `.worktrees/p19-16-audit`) |
| Production deploy | **NO — Not authorized** |

## Repository / ledger verification

| Check | Status |
|---|---|
| Target repo `radium-desk` | **VERIFIED** |
| Merge SHA `711173a4c` | **VERIFIED** (`git log -1`) |
| Tag `v4.1.95` | **VERIFIED** (on merged release) |
| P-19-15 artifact read | **VERIFIED** (`.worktrees/p19-15-leave-policy/.artifacts/...`) |
| Unrelated WIP (`feature/p14-38-desk-sole-payment-authority`) | **PRESERVED** (primary clone untouched) |

## Executive summary

Merged **v4.1.95** leave policy is structurally sound (Sunday sandwich, monthly cap, payroll/attendance effective dates, auto-approval off) but had **two deployment-blocking gaps** on `711173a4c`:

1. **Management `authorized_override` skipped coverage at approve time** — could approve violating admin-pair or triad rules.
2. **HR date-change amendments** never re-ran coverage locks/checks.
3. **Triad default env** previously implied a one-person triad (inactive rule). **v4.1.95 default `''`** is safer; prod still **BLOCKED** without three Owner-verified emails.

Corrections on `fix/p19-16-leave-policy-audit` close (1)–(2), fail-closed partial triad, fix **reset-by-reference** bug on manual-exception + coverage conflict, and add regressions.

**Go for production deployment:** **NO** until (a) fix PR merges and (b) Owner sets full `WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS` and runs staging migration + PRE-RIP.

---

## Findings (VERIFIED / INFERRED / UNKNOWN)

### Approval paths (mandatory trace)

| Path | Coverage cap | Monthly cap | Locks / concurrency |
|---|---|---|---|
| Employee `submit` | **VERIFIED** orchestrator + `lockCoverageGroupRows` | submit-time cap on new manual exceptions | `lockLeaveRequest` pattern on approve |
| `approve` (standard) | **VERIFIED** `assertCoverageAllows` always after fix | **VERIFIED** `assertManualExceptionAllowedOnApprove` | **VERIFIED** coverage group row locks |
| `approve` + `authorized_override` | **VERIFIED** after fix: coverage **not** skippable; override **only** bypasses monthly cap | **VERIFIED** | audit columns `authorized_override*` |
| HR half-day `manage-update` | **VERIFIED** (duration exception path) | N/A | existing payroll lock |
| Amendment `manageDateChange` | **VERIFIED** after fix | N/A | **VERIFIED** locks + policy effective range |
| Amendment `approve` (DateChange) | **VERIFIED** after fix | N/A | same |

**PRE-FIX on `711173a4c`:** override wrapped `assertCoverageAllows` — **HIGH** defect.

### Triad (Shubhanshi / Abhinav / Kanchan)

| Item | Status |
|---|---|
| Rule: not all three on same day (pending+approved, policy effective dates) | **VERIFIED** (`LeaveCoverageGuard::assertTriadCoverage`) |
| Override cannot waive triad/admin rules | **VERIFIED** after fix |
| Default env without triad emails | **VERIFIED** — rule **inactive** (`count < 3`); no false “active” partial group |
| Member of partial triad config | **VERIFIED** `assertTriadGroupReadyForMember` **fail-closed** after fix |
| `shubhanshi@radiumbox.com` in tests/docs | **VERIFIED** |
| Abhinav / Kanchan production emails | **UNKNOWN** — **no** authorized config in repo (names only in tests/commands) |
| Prod triad activation | **BLOCKED** — Owner must supply comma-separated triad env |

### Admin pair (Dileep / Avinash)

| Item | Status |
|---|---|
| Default `dileep@radiumbox.com,avinash@radiumbox.com` | **VERIFIED** `config/leave_policy.php` |
| Mutual exclusion on same calendar day | **VERIFIED** + test |
| Manual exception allows **submit** with conflict for review | **VERIFIED** orchestrator catch |
| Approve conflict even with override | **VERIFIED** after fix + test |

### Monthly manual exception cap (2 / calendar month)

| Item | Status |
|---|---|
| Counts **approved** `is_manual_exception` && !`authorized_override` | **VERIFIED** |
| Anchor = leave `start_date` month | **VERIFIED** |
| Pending / rejected / withdrawn excluded | **VERIFIED** (status filter) |
| Override allows 3rd+ with reason + manage permission | **VERIFIED** + test |
| Historical rows pre-migration (`is_manual_exception` default false) | **VERIFIED** not counted — **documented limitation** |
| Legacy manual exceptions not reliably classifiable | **VERIFIED** — no backfill in migration |

### Sunday sandwich

| Item | Status |
|---|---|
| &lt; 7 calendar days notice → expand to Sunday (Sat/Mon) | **VERIFIED** |
| Split Sat + Mon → single Sunday attach | **VERIFIED** test |
| 7+ days → no sandwich | **VERIFIED** |
| Attendance / payroll use policy effective dates | **VERIFIED** `WorkCalendarService`, `PayrollPayableDayPolicy` |

### Migration / payroll / attendance

| Item | Status |
|---|---|
| Migration additive; defaults safe for historical rows | **VERIFIED** |
| `down()` drops columns + FK | **VERIFIED** (destructive; staging only per policy) |
| Historical half-day preserved via `is_half_day_exception` + HR path | **VERIFIED** (P-19-15) |
| Payroll month lock on approve/amend | **VERIFIED** existing integration |
| Migration run on prod | **NOT RUN** (not authorized) |

### Auto-approval

| Item | Status |
|---|---|
| Disabled (`leave_balance_ledger_available` false) | **VERIFIED** |

---

## Test evidence (focused — full suite NOT RUN)

Environment: `/opt/homebrew/bin/php`, `PHP_INI_SCAN_DIR=`, worktree vendor symlink.

```
PHPUnit tests/Feature/Workforce/LeavePolicyControlsTest.php
         tests/Feature/Workforce/LeaveRequestNotificationWorkflowTest.php
Result: 16 tests, 37 assertions, PASS (~16s)
```

New regressions: override vs admin pair; override monthly cap; incomplete triad fail-closed.

---

## Git / PR status

| Item | Value |
|---|---|
| Baseline merged | PR **#350** @ `711173a4c`, **`v4.1.95`** |
| Fix branch | `fix/p19-16-leave-policy-audit` (pending commit/push/PR at artifact write) |
| Primary clone WIP | Unchanged |

---

## Owner decisions outstanding

1. Provide **verified** `WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS` (three comma-separated emails including Shubhanshi, Abhinav, Kanchan).
2. Confirm deployment order: merge P-19-16 fix → staging migration → PRE-RIP → prod (separate authorization).
3. Optional: backfill strategy if historical manual exceptions must count toward cap (**none today**).

---

## Rollback assessment

- **Code:** redeploy `v4.1.95` without migration if migration not yet applied; if applied, run migration `down()` only on staging first (drops policy columns — data loss for policy fields).
- **Config:** unset or correct triad env if misconfigured.

---

## Production deployment

**NO — Not authorized.**

**Not “all clear” for prod** until P-19-16 fix merges, triad emails configured, and staging migration verified.