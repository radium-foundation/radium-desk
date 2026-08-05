# Team Activity Query Budget — Final

**Date:** 2026-08-05  
**Scope:** `TeamActivityPanelService::build()` query budget (`<= 120` vs ~192–194)  
**Related:** [performance-release-blockers.md](./performance-release-blockers.md), [performance-sprint-team-activity.md](./performance-sprint-team-activity.md)  
**Constraints:** No Canvas.

---

## Verdict

**Real optimization applied. Benchmark stays at 120.**

| Metric | Before | After |
|--------|-------:|------:|
| `test_panel_build_query_count_stays_bounded` | **194** | **85** |
| Budget | 120 | **120 (unchanged)** |

Raising the threshold was **not** required. The overrun was duplicate / N+1 workforce and activity lookups on an otherwise correct panel build — not snapshot-cache breakage and not lazy-SSR cost (lazy SSR never calls `build()` on first paint).

---

## Pre-fix query mix (baseline ~194 / probe ~201)

Fixture: 3 tracked agents with open sessions + IRA audit (same shape as the budget test).

| Count | Table / area | Classification |
|------:|--------------|----------------|
| ~34 | `work_sessions` | **N+1 + duplicate** — per-member `openSessionFor` / `todaySessionFor` / availability checks; also batched summaries + working-hours open-user scan |
| ~31 | `leave_requests` | **N+1 + duplicate** — `approvedLeaveRequest` re-hit inside authority, calendar status, availability snapshot, attendance calc |
| ~26 | `workforce_attendance_days` | **Duplicate + open-user refresh** — `WorkingHoursTodayService::forUsers` twice (overview + presence); `resolveDay` for open sessions |
| ~24 | `users` | **Duplicate required bulk** — roster, KPI profiles, call/pending metrics, working-hours user load |
| ~24 | `company_holidays` | **N+1 / duplicate** — `isCompanyHoliday()` exists() per calendar/authority/attendance call |
| ~12 | `workforce_payroll_month_locks` | **Duplicate** — `isMonthLocked()` per open-user resolve/refresh |
| ~12 | `audit_logs` | **Mixed** — batched KPI/latest + **N+1** `previousActivityAtByUser` |
| ~10 | `roles` | **Duplicate required** — roster eager + KPI profile reload |
| ~8 | `orders` | **Required** — snapshot / activity presentation / IRA health |
| ~7 | `team_member_work_schedules` | **Mostly required** — roster eager; occasional open-user `loadMissing` |
| ~5–10 | incidents + waiting/holds/etc. | **Required (pending metrics)** — `CaseQueueReadModel` / `DashboardSnapshot` hydrate |
| 1 | `bonvoice_call_events` | **Required** — team IVR total |

### Classification summary

| Bucket | Role |
|--------|------|
| **Required** | Roster users/roles/schedules; dashboard snapshot for pending; KPI/audit aggregates; call metrics; PI badges |
| **Duplicate** | Second `WorkingHoursTodayService::forUsers`; repeated holiday/leave/session/lock checks inside one request |
| **N+1** | Per-user previous audit; per-member presence open/today session; per-call holiday/leave exists |
| **Workforce / Attendance / Leave / Holiday** | Dominant cost (~150+ of ~194) |
| **Snapshot / Pending metrics** | Small, request-scoped; not the budget breach |
| **Lazy SSR** | Orthogonal — does not change `build()` cost |

---

## Recommendation (executed)

**A — real optimization** (safe, request-scoped). **Do not raise 120.**

Unsafe / deferred (explicitly avoided):

- Global memoization of leave/holiday outside a read batch (session-start and mid-request approvals must see fresh rows).
- Skipping live attendance refresh for open sessions (would change displayed Working Hours Today).
- Broad workforce authority rewrite in this pass.

---

## Optimizations implemented

| Change | Why safe | Effect |
|--------|----------|--------|
| `WorkCalendarService::{begin,end}ReadBatch()` + holiday/leave memo **only while batch active** | Opt-in; `TeamActivityPanelService::build()` wraps the whole build; session-start / leave-approve paths stay uncached | Collapses ~24 holiday + ~31 leave repeats |
| `PresenceEngineService` request-scoped `openSessionFor` / `todaySessionFor` cache | Invalidated on start/close/timeout close | Collapses per-member session fan-out |
| `WorkingHoursTodayService::forUsers` request memo (scoped service) | Same roster + work date reused by overview then presence metrics | Removes second attendance/open-session pass |
| `PayrollMonthLockService::isMonthLocked` request memo | Cleared on lock/unlock | Collapses repeated month-lock exists |
| `previousActivityAtByUser` batched to 1–2 audit queries | Same “prior allowlisted audit today” semantics | Removes per-agent audit N+1 |
| Scope bindings in `AppServiceProvider` for Working Hours / Presence / Payroll lock | Required for request-local caches to stick | Enables above |

Files:

- `app/Services/Operations/WorkCalendarService.php`
- `app/Services/Dashboard/TeamActivityPanelService.php`
- `app/Services/Operations/WorkingHoursTodayService.php`
- `app/Services/Operations/PresenceEngineService.php`
- `app/Services/Workforce/PayrollMonthLockService.php`
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/DashboardTeamActivitySortingAndIraTest.php` (assertion message only)

---

## Residual cost (after ~85)

Still expected on expand:

- One roster load + authority evaluation per member (CPU; far fewer SQL repeats)
- One live attendance refresh per open session (correctness)
- Snapshot hydrate for pending counts
- KPI / latest-audit / call / badge batched reads

Further gains (later backlog): pass preloaded session maps into `WorkforceAuthorityService`, reuse roster `User` models in KPI/call/pending resolvers, optional short TTL metrics cache (M10).

---

## Tests

```bash
php artisan test --filter=DashboardTeamActivitySortingAndIraTest
php artisan test --filter=DashboardTeamActivity
```

| Suite | Result |
|-------|--------|
| `DashboardTeamActivitySortingAndIraTest` (incl. budget) | **Pass** (budget actual **85**) |
| Full `DashboardTeamActivity*` | 74 pass / 3 fail — **same 3 failures without this change** (UI copy / AutoLogout fixture / “Shift starts …” assert drift; unrelated) |

Pre-existing unrelated failures also confirmed outside this change: `TeamAvailabilityOverviewOptimizationTest` (counts both session-summary and working-hours `whereIn`), `PresenceEngineTest::test_superadmin_login_does_not_create_workforce_session` (redirect `admin/platform` vs `dashboard`).

---

## Why the benchmark must **not** change

1. Overrun was **fixable duplicate/N+1 cost**, not new required product work.
2. After safe request-scoped opts, measured build is **85 ≤ 120**.
3. Keeping 120 preserves a useful guard against future linear fan-out on expand.
4. Lazy Team Activity SSR remains the first-paint win; this closes the expand-path budget gap without weakening the guard.

---

*End of report.*
