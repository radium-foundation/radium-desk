# P-19-15 — Leave policy implementation report

| Field | Value |
|---|---|
| Global ID | P-19-15 |
| Cursor ledger ID | RadiumDesk-P-19-15 |
| Risk | **HIGH** |
| Start | 2026-11-19 08:52:13 IST |
| End | 2026-11-19 09:15:00 IST (approx) |
| Branch | `feature/p19-15-leave-policy` @ `main` `b6b39f081` |
| Production deploy | **NO** |

## PASSED

- Full-day employee path; half-day rejected server-side
- Sunday sandwich (6d vs 7d notice); dedupe across Sat+Mon same weekend
- Admin pair mutual exclusion (configured emails)
- Manual exception monthly cap (2 approved); authorized override on approve
- Auto-approval **disabled** (no leave-balance ledger)
- Migration additive; historical rows default-safe
- Focused PHPUnit: `LeavePolicyControlsTest` 7/7, `LeaveRequestNotificationWorkflowTest` 6/6

## BLOCKED / NOT RUN

- Full PHPUnit suite (Owner policy)
- Triad coverage in prod: **Abhinav/Kanchan emails UNKNOWN** — set `WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS`
- Auto-approval pilot until balance ledger + Owner enable
- Vite build in isolated worktree (2 UI tests env-blocked)

## Rollback

Run migration `down()` on staging only; redeploy prior release `v4.1.94`.