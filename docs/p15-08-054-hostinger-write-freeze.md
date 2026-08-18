# P15-08-054 — Hostinger write freeze (executed)

**Status: FROZEN.** No extract, transport, apply, or DNS change.

hPanel cron rows cannot be edited over SSH. Cron #1 and #2 were neutralized at their entrypoints and still fire every minute as no-ops (see freeze log).

Backups: `/home/u215544208/lcds-freeze-backups-20260815T165156Z/` (`schedule-run.sh`, `queue-worker.sh`, `artisan`).

| Control | Action |
|---|---|
| Cron #1 | `bin/schedule-run.sh` replaced with exit-0 stub |
| Cron #2 | `~/queue-worker.sh` replaced with exit-0 stub |
| Artisan writers | Hostinger `artisan` refuses `schedule:run` / `queue:work` / recover / gmail / etc. while `storage/framework/lcds-write-freeze` exists |
| HTTP | `php artisan down --retry=120` |
| MySQL | left running |
| Cashfree dashboard | not modified |
| VPS | still dark |

Watermarks unchanged across T0 / T_freeze / T+75s / T+110s:

| Table | count | max(id) | max(updated_at) |
|---|---|---|---|
| orders | 38637 | 38849 | 2026-08-15 22:20:18 |
| incidents | 39744 | 39858 | 2026-08-15 22:13:17 |
| cashfree_webhook_logs | 47179 | 47179 | 2026-08-15 22:13:17 |
| finance_journals | 12311 | 12311 | 2026-08-15 22:13:25 |
| finance_journal_lines | 24622 | 24622 | 2026-08-15 22:13:25 |
| `sc` | — | **39898** | 2026-08-15 22:13:17 |

desk and `POST /api/webhooks/cashfree` → **503**. `jobs=0`. DNS still `187.127.183.72`.
