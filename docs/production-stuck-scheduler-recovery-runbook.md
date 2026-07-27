# Production Runbook: Stuck Scheduler Recovery

**Scope:** Restore queue processing, RadiumBox enrichment, and Ready Queue after a stuck Laravel scheduler.  
**Out of scope:** Scheduler redesign, Ready Queue / RadiumBox / IRA business-logic changes.

## Symptoms

- `jobs` table backlog grows while cron still fires `schedule:run`
- RadiumBox enrichment stalls (`pending` / `failed` / missing serial+model)
- Newly paid or manually enriched cases do not enter Ready Queue
- Hostinger-style environments where `queue:work` only runs from `schedule:run`

## Likely root cause (current Hostinger topology)

1. Cron runs `php artisan schedule:run` every minute.
2. Scheduled events use `withoutOverlapping()` mutexes.
3. A long-running event (historically `inbound-email:sync-gmail`) can hold a host-level flock / mutex.
4. Later events in the same `schedule:run` — including `queue:work --stop-when-empty` — never start.
5. Backlog compounds: enrichment jobs, automation-pending, Ready Queue catch-up all stall.

## Safety rules

- Prefer `--dry-run` first.
- Prefer bounded `--limit` / `--chunk`.
- All recovery commands are idempotent: safe to re-run.
- Do **not** redesign the scheduler during an incident.
- Do **not** run unbounded `queue:work` without `--stop-when-empty` / `--max-time` on shared hosting.

## Recovery commands

| Command | Purpose |
|---------|---------|
| `production:recover-queues` | Orchestrated recovery (locks → drain → RadiumBox sync → automation pending → Ready Queue) |
| `radiumbox:backfill-sync` | Re-queue missed / failed / stale RadiumBox enrichment using existing dispatch/retry paths |
| `radiumbox:backfill-ready-queue` | Re-evaluate active cases that missed Ready Queue entry (alias: `readyqueue:backfill`) |

Shared flags on all three:

```bash
--dry-run
--limit=
--chunk=
```

## Immediate recovery procedure

### 0) Pre-flight checks

```bash
php artisan about | head
php artisan tinker --execute="echo 'jobs='.DB::table('jobs')->count().PHP_EOL.'failed='.DB::table('failed_jobs')->count().PHP_EOL;"
```

Optional visibility:

```bash
php artisan queue:failed
tail -n 100 storage/logs/queue-worker.log
tail -n 100 storage/logs/inbound-email-gmail-sync.log
tail -n 100 storage/logs/laravel.log
```

### 1) Dry-run orchestrator

```bash
php artisan production:recover-queues \
  --dry-run \
  --clear-schedule-locks \
  --drain-queue \
  --limit=100 \
  --chunk=50 \
  --skip-repairs
```

Confirm the printed steps match expectations. No mutations occur in dry-run (except read-only reporting).

### 2) Execute recovery (bounded)

```bash
php artisan production:recover-queues \
  --clear-schedule-locks \
  --drain-queue \
  --limit=100 \
  --chunk=50 \
  --max-time=55 \
  --drain-passes=20 \
  --skip-repairs
```

What this does:

1. `schedule:clear-cache` — releases stuck `withoutOverlapping` mutexes
2. Drains `jobs` via repeated `queue:work --stop-when-empty --max-time=55`
3. `radiumbox:backfill-sync` — dispatches / retries missed enrichment
4. Intermediate queue drain for newly dispatched enrichment jobs
5. `service-cases:process-automation-pending`
6. `radiumbox:backfill-ready-queue` — eligibility re-evaluation / enrichment catch-up for active cases
7. Final queue drain

Omit `--skip-repairs` only if you also want the existing idempotent repair commands (`incidents:repair-serial-waiting`, `automation:repair`).

### 3) Continue catch-up in waves if backlog remains

```bash
php artisan radiumbox:backfill-sync --limit=200 --chunk=50
php artisan queue:work --stop-when-empty --max-time=55
php artisan radiumbox:backfill-ready-queue --limit=200 --chunk=50
php artisan queue:work --stop-when-empty --max-time=55
```

Repeat until:

- `jobs` near zero
- dry-run backfills report no unexpected work

## Standalone command usage

### RadiumBox sync backfill

```bash
# Preview
php artisan radiumbox:backfill-sync --dry-run --limit=50 --chunk=50

# Execute
php artisan radiumbox:backfill-sync --limit=100 --chunk=50

# Single order
php artisan radiumbox:backfill-sync --order=RD1234567
```

Uses existing enrichment dispatch / retry services only. Skips non-stale pending, already-complete, and unsafe failed recoveries.

### Ready Queue backfill

Alias: `readyqueue:backfill`

```bash
# Preview
php artisan radiumbox:backfill-ready-queue --dry-run --limit=50 --chunk=50

# Execute
php artisan radiumbox:backfill-ready-queue --limit=100 --chunk=50
```

For active service cases only. Skips cases already assigned to Ready Queue admins. Idempotent.

## Validation checklist

After recovery:

- [ ] `jobs` count near zero (or steadily draining each minute)
- [ ] `failed_jobs` reviewed; retry only known-good failures
- [ ] RadiumBox dry-run shows no unexpected backlog:  
      `php artisan radiumbox:backfill-sync --dry-run --limit=50`
- [ ] Ready Queue dry-run shows no unexpected backlog:  
      `php artisan radiumbox:backfill-ready-queue --dry-run --limit=50`
- [ ] Manual serial entry on a paid order reaches Ready Queue
- [ ] Newly created paid order enrichment reaches Ready Queue
- [ ] No duplicate Ready Queue admin assignments for the same case
- [ ] Next cron `schedule:run` executes `queue:work` again (check `storage/logs/queue-worker.log`)

## Estimated runtime (Hostinger shared)

| Step | Estimate |
|------|----------|
| Clear schedule locks | seconds |
| Drain 50–200 jobs (`--max-time=55`) | 1–5 minutes per wave |
| `radiumbox:backfill-sync --limit=100` | seconds to dispatch; minutes to complete via queue |
| `radiumbox:backfill-ready-queue --limit=100` | seconds to minutes (mostly DB + optional job dispatch) |
| Full catch-up of a multi-hour outage | multiple bounded waves; prefer `--limit` loops over one unbounded run |

## Rollback / abort

These commands mostly **dispatch** existing jobs or call existing eligibility evaluation. There is no destructive schema change.

If something looks wrong mid-run:

1. Stop issuing further artisan recovery commands.
2. Let in-flight `queue:work --stop-when-empty` finish (or wait for `--max-time`).
3. Inspect logs and `failed_jobs`.
4. Prefer dry-run before the next wave.
5. Do **not** `queue:flush` / `queue:clear` unless an on-call owner explicitly approves discarding pending work.

Rollback of a bad deploy remains a normal release rollback; this runbook does not replace deploy rollback.

## Risk assessment

| Risk | Mitigation |
|------|------------|
| Duplicate enrichment API calls | Existing sync-store pending/synced guards; commands skip non-stale pending |
| Duplicate Ready Queue assignment | Existing eligibility + admin-role skip; command is idempotent |
| Long CLI holding hosting resources | `--limit`, `--chunk`, `--max-time`, `--drain-passes` |
| Clearing schedule locks while a legit job runs | Prefer during confirmed stall; `schedule:clear-cache` only clears mutexes |
| Masking scheduler redesign need | Treat this as incident recovery only; leave redesign to a separate PR |

## Related existing commands (unchanged)

These remain available and are not replaced by this hotfix:

- `radiumbox:recover-sync` — scheduled failed/stale recovery
- `radiumbox:backfill-orders` — historical paid-order enrichment backfill
- `service-cases:process-automation-pending`
- `schedule:clear-cache`
- `queue:work --stop-when-empty --max-time=55`

## Post-incident

1. Confirm cron still runs `schedule:run` every minute.
2. Confirm queue worker log advances each minute.
3. Hand off root-cause scheduler contention to the scheduler redesign track (separate PR / chat).
4. Keep this runbook for the next stuck-scheduler incident.
