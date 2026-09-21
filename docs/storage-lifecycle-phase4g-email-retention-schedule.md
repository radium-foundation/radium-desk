# Phase 4G — Recurring inbound email retention schedule

**Date:** 2026-09-21  
**Prompt:** RadiumDesk-P-21-09-14

## Policy

| Population | Retention | Automatic purge |
|------------|-----------|-----------------|
| Order-linked (`order_id IS NOT NULL`) | Permanent | **Never** |
| `unknown_customer` ignored | 30 days | Weekly via `database:retention-prune-unknown-customer --execute` |
| Approved Phase 4B noise ignore reasons | 90 days | Weekly via `database:retention-prune-ignored-email --execute` |
| `needs_review` | Operator disposition | **Never** |
| Other / ambiguous | Keep | **Never** |

Approved noise ignore reasons (only):

- `own_outbound`
- `known_system_email`
- `newsletter_or_marketing`
- `auto_responder`
- `bounce_or_delivery_subsystem`

`unknown_customer` is **not** in the noise allowlist.

## Orchestration

- **Command:** `database:retention-email-schedule`
- **Wrapper:** `bin/email-retention-schedule.sh` (flock on `/var/lock/radium-desk-email-retention.lock`)
- **Audit artifacts:** `/var/backups/radium-desk/ignored-email-retention/`
- **Scheduler flag:** `RETENTION_EMAIL_SCHEDULER_ENABLED=true` (default **false** in config)

### Daily dry-run — 04:00 Asia/Kolkata

```bash
/usr/bin/flock -n /var/lock/radium-desk-email-retention.lock \
  bash -c 'cd /var/www/radium-desk && ./bin/email-retention-schedule.sh --dry-run-only' \
  >> /var/www/radium-desk/storage/logs/email-retention-schedule.log 2>&1
```

Runs fresh dry-runs for both populations, writes manifests + JSON audit logs, records monitoring metrics. **No deletes.**

### Weekly execute — Sunday 04:15 Asia/Kolkata

```bash
/usr/bin/flock -n /var/lock/radium-desk-email-retention.lock \
  bash -c 'cd /var/www/radium-desk && ./bin/email-retention-schedule.sh --weekly-execute' \
  >> /var/www/radium-desk/storage/logs/email-retention-schedule.log 2>&1
```

Before deletion:

1. Fresh dry-run + manifests
2. Configuration validation (30d / 90d thresholds, allowlist)
3. Recovery backup availability check
4. Safety gates (order/incident/outbox/reply dependencies)
5. >20% candidate growth abort vs previous daily dry-run

After deletion:

1. Post-execution dry-run for both populations
2. Audit JSON + schedule state update

## Safety

- Concurrent runs abort via flock (shell + PHP `LOCK_NB`).
- `needs_review` is reported as backlog metric only; never purged.
- Audit log retention: 30 daily JSON files, 12 weekly JSON files (configurable).
- Do **not** run `OPTIMIZE TABLE` as part of this schedule.

## Manual verification (non-destructive)

```bash
cd /var/www/radium-desk
RETENTION_EMAIL_SCHEDULER_ENABLED=true \
  php artisan database:retention-email-schedule --dry-run-only
```

Do not run `--weekly-execute` manually unless executing an approved maintenance window.
