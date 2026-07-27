# Working Hours Attribution Runbook

## Canonical definition

**Working Hours Today** = `workforce_attendance_days.active_duration_seconds`

All roles (Agent, Supervisor, Admin, Super Admin) read this via `WorkingHoursTodayService`.

Do not sum raw `work_sessions.session_duration_seconds` for “hours today” in UI code.

## Concepts kept separate

| Concept | Source |
|---|---|
| Working Hours | Attendance register (`active_duration_seconds`) |
| Current Status | PresenceEngine / current WorkSession |
| Productivity | KPI / performance metrics |

## Session attribution

`work_sessions` columns:

- `origin`: `login` | `browser` | `system` | `assignment` | `migration`
- `is_attributable`: when `false`, attendance and Working Hours ignore the session

New browser/login sessions are attributable. Historical rows default to `origin=migration`, `is_attributable=true` until explicitly marked.

## Repair a historical ghost (do not delete)

Example: Sumit phantom session `1391` (user 12, 2026-07-27):

```bash
# Inspect first
php artisan work-sessions:set-attribution --id=1391 --origin=assignment --attributable=0 --dry-run

# Apply + refresh that user's attendance day only
php artisan work-sessions:set-attribution --id=1391 --origin=assignment --attributable=0 --reconcile
```

Or two-step:

```bash
php artisan work-sessions:set-attribution --id=1391 --origin=assignment --attributable=0
php artisan attendance:reconcile-days --user=12 --from=2026-07-27 --to=2026-07-27
```

Never run blanket attribution updates without reviewing session ids.

## Future ghost prevention

1. Assignment / business activity uses `createIfMissing: false` (cannot create WorkSessions).
2. Only login / heartbeat / middleware / tracked browser context create sessions (`login` / `browser`).
3. Attendance sums attributable sessions only.
