# P-19-17 — Operational coverage configuration

## Owner-provided triad emails (P-19-17)

Set at deploy time (never commit live `.env`):

```dotenv
WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS=shubhanshi@radiumbox.com,abhinavjharadiumbox@gmail.com,kanchan@radiumbox.com
WORKFORCE_LEAVE_ADMIN_PAIR_LEAVE_EMAILS=dileep@radiumbox.com,avinash@radiumbox.com
WORKFORCE_LEAVE_AUTO_APPROVAL_ENABLED=false
```

`config/leave_policy.php` reads these values. Empty triad env keeps the rule **inactive**; partial lists **fail closed** for listed members (P-19-16).

## Production identity mapping (read-only verification, 2026-11-19)

Matched by exact email on live Desk `users` (active accounts only in mapping):

| Email (Owner-provided) | `users.id` | Active |
| --- | ---: | :---: |
| `shubhanshi@radiumbox.com` | 6 | yes |
| `abhinavjharadiumbox@gmail.com` | 9 | yes |
| `kanchan@radiumbox.com` | 17 | yes |
| `dileep@radiumbox.com` (admin pair) | 4 | yes |
| `avinash@radiumbox.com` (admin pair) | 2 | yes |

Do not enable triad on an environment until all three triad addresses resolve to **distinct active** users.

## Staging (`radium-desk-uat`)

- Database: `radium_desk_uat` (**VERIFIED** isolated from production).
- UAT fixture data **does not** include the triad users (2026-11-19) — configure env after a data sync or synthetic users before triad UAT.
- Apply release **v4.1.96+** and migration `2026_11_19_100000_add_leave_policy_columns_to_leave_requests_table.php` on staging only.

## Override scope

`authorized_override` on approve bypasses the **monthly manual-exception cap** only. Coverage (triad + admin pair) cannot be overridden (P-19-16).

## See also

- `docs/P-19-15-leave-policy-controls.md`
- `.artifacts/P-19-16-leave-policy-deployment-gate-audit.md`