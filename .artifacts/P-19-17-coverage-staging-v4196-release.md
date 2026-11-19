# P-19-17 — Coverage configuration, staging validation, v4.1.96

| Field | Value |
|---|---|
| Global ID | P-19-17 |
| Cursor ledger ID | **RadiumDesk-P-19-17** |
| Risk | **HIGH** |
| Start | 2026-11-19 **09:49:00 IST** |
| End | 2026-11-19 **~09:56 IST** |
| Baseline | `origin/main` @ **`e7d80ae9c`** (PR **#351**) |
| Branch | `release/p19-17-v4196` |
| Production deploy / prod migration / prod env | **NO — Not performed** |

## Identity mapping (VERIFIED — read-only prod lookup)

Owner-provided emails matched to **distinct active** `users` rows (ids only; no names in evidence):

| Email | `users.id` | Active |
| --- | ---: | :---: |
| `shubhanshi@radiumbox.com` | 6 | 1 |
| `abhinavjharadiumbox@gmail.com` | 9 | 1 |
| `kanchan@radiumbox.com` | 17 | 1 |
| `dileep@radiumbox.com` | 4 | 1 |
| `avinash@radiumbox.com` | 2 | 1 |

**INFERRED:** prod **`WORKFORCE_LEAVE_*` unset** — triad rule inactive until deploy env set (not done on prod in P-19-17).

## Configuration (staging only)

**VERIFIED** appended to `/var/www/radium-desk-uat/.env` (not production):

- `WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS` = Owner triad CSV  
- `WORKFORCE_LEAVE_AUTO_APPROVAL_ENABLED=false`

**UNKNOWN on UAT:** triad users absent in `radium_desk_uat` — `triad_configured` **false** until data sync or fixtures.

## Staging migration (VERIFIED)

| Step | Evidence |
| --- | --- |
| Isolation | `APP_ENV=staging`, `DB_DATABASE=radium_desk_uat` |
| Recovery point | `storage/app/p19-17-backup/20261119T095200Z-P19-17.sql.gz` (**60876** bytes) |
| Initial migrate | **FAIL** — MySQL index name &gt; 64 chars (discovered on UAT) |
| Fix | Short index names + idempotent migration |
| Migrate / rollback / migrate | **PASS** on UAT |
| Indexes | `leave_req_manual_exc_status_idx`, `leave_req_policy_eff_idx` |

**Note:** UAT may retain duplicate legacy index `leave_requests_user_id_is_manual_exception_status_index` from the failed first attempt — non-blocking; optional DBA cleanup.

## Tests (focused; full suite NOT RUN)

```
LeavePolicyControlsTest          10/10 PASS
LeavePolicyTriadCoverageTest      3/3 PASS
LeaveRequestNotificationWorkflowTest 6/6 PASS
Total 19 tests, exit 0 (~19s)
```

## Release

- **Target:** **`v4.1.96`** including **#350**, **#351**, P-19-17 docs/tests/migration fix  
- **Tag / merge:** pending PR merge (see Git status in ledger row)

## Production gate

**NO deploy.** Before prod: set triad/admin env on production host, PRE-RIP, `DEPLOY_RUN_MIGRATIONS=1` with backup, post-deploy smoke on leave paths.