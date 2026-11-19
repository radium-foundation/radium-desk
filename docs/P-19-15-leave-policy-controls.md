# P-19-15 — Leave policy controls

## Configuration

- `config/leave_policy.php` — Sunday sandwich, coverage emails, manual exception cap, auto-approval gates.
- **Triad coverage:** set `WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS` (comma-separated). **Abhinav and Kanchan emails are not in repo** — Owner must supply.
- **Admin pair:** default `dileep@radiumbox.com,avinash@radiumbox.com` (verified in tests).
- **Auto-approval:** `WORKFORCE_LEAVE_AUTO_APPROVAL_ENABLED=false` (default). Blocked until leave-balance ledger exists.

## Behaviour

- Employees: full-day only; half-day via HR manage + `half_day_exception`.
- Sunday sandwich: Sat/Mon with &lt;7 calendar days from `created_at` → effective range includes Sunday (deduped per weekend).
- Manual exceptions: max 2 approved per employee per calendar month (`is_manual_exception`, not `authorized_override`).
- Authorized override on approve (requires `leave-requests.manage` + reason) for cap/coverage edge cases.

## Rollback

- Migration `2026_11_19_100000_add_leave_policy_columns_to_leave_requests_table.php` is additive; rollback drops new columns only.