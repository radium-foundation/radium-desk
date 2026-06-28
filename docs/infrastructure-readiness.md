# Infrastructure Readiness — Radium Desk

**Task:** P28-06-010 — Infrastructure Readiness & Queue Architecture (Hostinger → VPS)

This document is the production infrastructure blueprint for Radium Desk. Application architecture stays fixed; only infrastructure changes when moving from Hostinger shared hosting to VPS, Redis, and Horizon.

---

## 1. Infrastructure Readiness Report

### Current state

| Area | Current configuration | Hostinger-ready | VPS-ready |
|------|----------------------|-----------------|-----------|
| Queue driver | `database` (`QUEUE_CONNECTION=database`) | Yes | Yes (swap to `redis`) |
| Failed jobs | `database-uuids` → `failed_jobs` table | Yes | Yes |
| Cache | `database` (`CACHE_STORE=database`) | Yes | Yes (swap to `redis`) |
| Session | `database` (`SESSION_DRIVER=database`) | Yes | Yes (swap to `redis`) |
| Logging | `stack` → `single` file | Yes | Yes (add `daily` on VPS) |
| Background jobs | 1 queued job class | Yes | Yes |
| Scheduler | Laravel scheduler via cron | Yes (when enabled) | Yes |
| Horizon | Not installed | N/A | Future |
| Redis | Config present, not required | N/A | Future |

### Application queue usage

Only one job is queued today:

| Job | Trigger | External I/O | Max attempts | Backoff |
|-----|---------|--------------|--------------|---------|
| `RadiumBoxOrderEnrichmentJob` | Cashfree webhook → order created | RadiumBox HTTP API | 4 | 60s, 300s, 1800s |

Cashfree webhook processing itself runs **synchronously** in the HTTP request but **does not** call RadiumBox. It dispatches enrichment to the queue and returns quickly.

### Health endpoint

Laravel exposes `GET /up` (configured in `bootstrap/app.php`). Use this for uptime checks on Hostinger and VPS.

---

## 2. Queue Configuration Audit

### Current queue driver

```env
QUEUE_CONNECTION=database
```

Default in `config/queue.php`: `env('QUEUE_CONNECTION', 'database')`.

### Queue connection (database)

| Setting | Value | Source |
|---------|-------|--------|
| Driver | `database` | `config/queue.php` |
| Table | `jobs` | `DB_QUEUE_TABLE` (default `jobs`) |
| Queue name | `default` | `DB_QUEUE` |
| DB connection | App default | `DB_QUEUE_CONNECTION` (optional) |
| `retry_after` | 90 seconds | `DB_QUEUE_RETRY_AFTER` |
| `after_commit` | `false` | Hard-coded |

Migration `0001_01_01_000002_create_jobs_table.php` creates `jobs`, `job_batches`, and `failed_jobs`.

### Failed jobs configuration

| Setting | Value |
|---------|-------|
| Driver | `database-uuids` (`QUEUE_FAILED_DRIVER`) |
| Table | `failed_jobs` |
| Connection | App default DB |

Failed jobs store UUID, connection, queue, payload, exception, and `failed_at`.

### Retry configuration

**Job-level (authoritative for `RadiumBoxOrderEnrichmentJob`):**

- `$tries = 4` (1 initial + 3 retries)
- `$backoff = [60, 300, 1800]` seconds
- Retriable failures throw `RadiumBoxEnrichmentRetryException`
- Terminal failure calls `failed()` → `markFailed()` + `Log::warning`

**Worker-level (cron / CLI):**

- Recommended Hostinger command uses `--stop-when-empty --max-time=55`
- Job `$tries` takes precedence over worker `--tries` when both are set

**Database driver `retry_after` (90s):**

- If a worker dies while a job is reserved, the job becomes available again after 90 seconds
- Must exceed longest expected job runtime (RadiumBox timeout ≈ 5s connect + 5s request → safe)

### Queue timeout

- No global `$timeout` on `RadiumBoxOrderEnrichmentJob` (uses Laravel default worker timeout)
- RadiumBox HTTP client: `RADIUMBOX_TIMEOUT_SECONDS=5`, `RADIUMBOX_CONNECT_TIMEOUT_SECONDS=3`
- Cron worker `--max-time=55` caps total worker lifetime per cron invocation

### Queue worker compatibility

| Worker mode | Hostinger | VPS (pre-Horizon) | VPS (Horizon) |
|-------------|-----------|-------------------|---------------|
| `queue:work --stop-when-empty` via cron | **Recommended** | Supported | Replaced by Horizon |
| Long-running `queue:work` / `queue:listen` | **Not recommended** | Supervisor | Horizon |
| `sync` driver | Dev/test only | Dev/test only | Dev/test only |

Local development uses `composer dev` which runs `queue:listen` — acceptable for dev only.

---

## 3. Risk Assessment

| Risk | Severity | Status | Mitigation |
|------|----------|--------|------------|
| No cron queue worker on Hostinger | **High** | Configurable | Set `QUEUE_CRON_WORKER_ENABLED=true` + Hostinger cron |
| RadiumBox enrichment delayed up to 1 min on shared hosting | Medium | Accepted | Cron every minute; acceptable for background enrichment |
| Order workspace page calls RadiumBox synchronously | Medium | Documented | `OrderController::show()` → `enrichOrderForWorkspace()`; 5s timeout; cached via `RadiumBoxRequestCache` |
| Duplicate Cashfree webhook processing | Low | Mitigated | Idempotency via `cashfree_payment_id` + processed webhook log lookup |
| Duplicate enrichment jobs for same order | Low | Partial | Multiple dispatches possible; job is idempotent (checks `needsEnrichment`); sync store tracks status |
| No `ShouldBeUnique` on enrichment job | Low | Accepted | Re-dispatch overwrites sync store to `PENDING`; safe but may cause extra API calls |
| Database queue under load | Medium | Future | Migrate to Redis on VPS |
| Shared hosting process/time limits | Medium | Mitigated | `--stop-when-empty --max-time=55` |
| Reverb/WebSockets on shared hosting | Medium | Separate concern | Reverb requires persistent process; use polling mode on Hostinger (`DASHBOARD_LIVE_MODE=auto`) |
| Failed job accumulation unnoticed | Medium | Mitigated | `infrastructure:metrics:collect` + `failed_jobs` table monitoring |
| Redis/Horizon not installed | Low | By design | Env-only migration path |

---

## 4. Recommended Hostinger Configuration

### Environment variables (production)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example

DB_CONNECTION=mysql
# ... Hostinger MySQL credentials ...

QUEUE_CONNECTION=database
QUEUE_CRON_WORKER_ENABLED=true
INFRASTRUCTURE_METRICS_ENABLED=true

CACHE_STORE=database
SESSION_DRIVER=database

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

BROADCAST_CONNECTION=log
# Reverb requires a persistent process — use polling on shared hosting:
DASHBOARD_LIVE_MODE=poll
```

### Cron (required)

Add in Hostinger hPanel → Cron Jobs:

```cron
* * * * * cd /home/USER/domains/DOMAIN/public_html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Adjust paths to match your Hostinger document root (often `public_html` or a subdirectory if the app root is above `public/`).

The scheduler runs (when flags are enabled):

1. `queue:work --stop-when-empty --max-time=55` every minute
2. `infrastructure:metrics:collect` every five minutes

### Queue worker strategy (preferred)

```
Cron every minute
        ↓
php artisan schedule:run
        ↓
queue:work --stop-when-empty --max-time=55
```

**Do not** run long-running `queue:work` or `queue:listen` on shared hosting.

Worker output appends to `storage/logs/queue-worker.log`.

### Shared hosting limitations

| Limitation | Impact | Workaround |
|------------|--------|------------|
| No persistent processes | Cannot run Horizon, Reverb, or daemon workers | Cron + `--stop-when-empty` |
| PHP max execution time | Long workers killed | `--max-time=55` |
| No Redis (typically) | Use database queue/cache/session | Env defaults already correct |
| Cron minimum 1 minute | Job latency up to ~60s | Acceptable for enrichment |
| `withoutOverlapping` lock | Prevents overlapping cron workers | Enabled in scheduler |

---

## 5. Queue Monitoring (Design)

Lightweight monitoring is implemented without UI. Future dashboard can consume cached snapshots.

### Metrics captured

| Metric | Source |
|--------|--------|
| Pending jobs | `jobs` table count |
| Failed jobs | `failed_jobs` table count |
| Last successful job | Cache (`infrastructure:queue:last_success_at`) |
| Average processing time | Rolling cache sample (last 100 jobs) |
| Queues in use | Distinct `jobs.queue` values |

### Services

- `App\Infrastructure\Queue\QueueMetricsService`
- `App\Infrastructure\Queue\QueueMetricsSnapshot`

### Collection

```bash
php artisan infrastructure:metrics:collect
```

Cached at `infrastructure:queue:metrics:latest` (24h TTL).

When `INFRASTRUCTURE_METRICS_ENABLED=true`, collection runs every five minutes via scheduler.

### Future dashboard integration

Expose a read-only admin endpoint or Filament widget that calls:

```php
app(QueueMetricsService::class)->latest();
app(IntegrationHealthRegistry::class)->latestCached();
```

No UI is implemented in this task.

---

## 6. Integration Health (Architecture)

Each integration exposes a standard health snapshot via `IntegrationHealthProbe`:

| Field | Description |
|-------|-------------|
| `connection_status` | `healthy`, `degraded`, `disabled`, `idle`, `not_configured`, `unknown` |
| `last_success_at` | Last successful operation |
| `last_failure_at` | Last failed operation |
| `last_sync_at` | Last data sync |
| `retry_count` | Retries in rolling window |
| `average_response_time_ms` | Rolling average |
| `last_error_message` | Most recent error |

### Implemented probes

| Integration | Probe | Data source |
|-------------|-------|-------------|
| Cashfree | `CashfreeIntegrationHealthProbe` | `cashfree_webhook_logs` |
| RadiumBox | `RadiumBoxIntegrationHealthProbe` | Cache aggregate (updated on enrichment attempts) |
| WhatsApp | Placeholder | `not_configured` |
| Email | Placeholder | `not_configured` |
| Shipping | Placeholder | `not_configured` |
| AI | Placeholder | `not_configured` |

### Registry

- `App\Infrastructure\IntegrationHealth\IntegrationHealthRegistry`
- Registered in `InfrastructureServiceProvider`

Future integrations add a probe class and register it — no changes to business services required.

---

## 7. Queue Safety Verification

| Requirement | Verdict | Evidence |
|-------------|---------|----------|
| Webhook never blocks on external APIs | **Pass** | `CashfreeWebhookProcessorService` dispatches `RadiumBoxOrderEnrichmentJob`; no HTTP calls in webhook path |
| External APIs never block requests (queued path) | **Pass** | RadiumBox API only called inside queued job |
| External APIs never block requests (all paths) | **Partial** | `OrderController::show()` calls RadiumBox synchronously with 5s timeout + request cache |
| Retries are bounded | **Pass** | `$tries = 4`, backoff array length 3 |
| Failures logged | **Pass** | `Log::warning` in job `failed()`; `Log::info` per attempt; webhook failures in `cashfree_webhook_logs` |
| No infinite retry loops | **Pass** | Laravel max attempts + non-retriable outcomes exit without rethrow |
| No duplicate processing (webhooks) | **Pass** | Payment ID idempotency in `findExistingIncidentForPayment()` |
| No duplicate processing (enrichment) | **Acceptable** | Idempotent updates; possible redundant API calls on re-dispatch |

---

## 8. Operational Commands

### Hostinger (shared hosting)

| Task | Command |
|------|---------|
| Run scheduler (cron) | `php artisan schedule:run` |
| Process pending jobs once | `php artisan queue:work --stop-when-empty --max-time=55` |
| List failed jobs | `php artisan queue:failed` |
| Retry one failed job | `php artisan queue:retry <uuid>` |
| Retry all failed jobs | `php artisan queue:retry all` |
| Forget one failed job | `php artisan queue:forget <uuid>` |
| Flush all failed jobs | `php artisan queue:flush` |
| Clear pending jobs | `php artisan queue:clear` |
| Collect metrics | `php artisan infrastructure:metrics:collect` |
| View queue worker log | `tail -f storage/logs/queue-worker.log` |

**Restart queue on Hostinger:** There is no daemon to restart. The next cron minute starts a fresh worker. To force immediate processing:

```bash
php artisan queue:work --stop-when-empty --max-time=55
```

### Future VPS (without Horizon)

| Task | Command |
|------|---------|
| Long-running worker (Supervisor) | `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600` |
| Restart workers | `php artisan queue:restart` |
| Supervisor reload | `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart radium-queue:*` |

After changing `.env` queue settings:

```bash
php artisan optimize:clear
php artisan config:cache
```

### Future VPS (with Horizon)

| Task | Command |
|------|---------|
| Start Horizon | `php artisan horizon` |
| Pause | `php artisan horizon:pause` |
| Continue | `php artisan horizon:continue` |
| Terminate (Supervisor restarts) | `php artisan horizon:terminate` |
| Dashboard | `/horizon` (protect with gate in production) |

---

## 9. Logging Strategy

| Environment | Recommended |
|-------------|-------------|
| Hostinger production | `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_LEVEL=info` |
| VPS production | `daily` + external log shipper (optional) |
| Local | `single`, `LOG_LEVEL=debug`, `php artisan pail` via `composer dev` |

Key log markers:

- `[Cashfree Webhook]` — webhook receipt and errors
- `RadiumBox order enrichment attempt completed.` — job attempts with `duration_ms`, `result`
- `RadiumBox order enrichment exhausted retries.` — terminal job failure
- `storage/logs/queue-worker.log` — cron worker output

---

## 10. Cache Strategy

| Phase | Driver | Notes |
|-------|--------|-------|
| Hostinger | `database` | Uses `cache` table; no Redis required |
| VPS | `redis` | Set `CACHE_STORE=redis` |
| Migration | Env only | No code changes |

Infrastructure metrics and integration health aggregates use the active cache driver.

---

## 11. Session Strategy

| Phase | Driver |
|-------|--------|
| Hostinger | `database` (`sessions` table) |
| VPS | `database` or `redis` |
| Migration | `SESSION_DRIVER=redis` when Redis available |

---

## 12. Backup Considerations

Back up on Hostinger and VPS:

| Asset | Priority |
|-------|----------|
| MySQL database | Critical — orders, incidents, jobs, failed_jobs, webhook logs |
| `storage/` (uploads, logs) | High |
| `.env` | Critical (secure storage, not in git) |
| Code | Medium (git is source of truth) |

Before VPS migration:

1. Export MySQL dump
2. Note cron entries and env vars
3. Drain or process `jobs` table before cutover
4. Verify `failed_jobs` is empty or retry/flush as needed

---

## 13. Environment Variables Reference

| Variable | Default | Purpose |
|----------|---------|---------|
| `QUEUE_CONNECTION` | `database` | Queue driver |
| `QUEUE_FAILED_DRIVER` | `database-uuids` | Failed job storage |
| `DB_QUEUE_RETRY_AFTER` | `90` | Reserved job release timeout |
| `QUEUE_CRON_WORKER_ENABLED` | `false` | Enable cron queue worker via scheduler |
| `INFRASTRUCTURE_METRICS_ENABLED` | `false` | Enable scheduled metrics collection |
| `CACHE_STORE` | `database` | Cache driver |
| `SESSION_DRIVER` | `database` | Session driver |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | Future Redis |
| `RADIUMBOX_TIMEOUT_SECONDS` | `5` | External API timeout |
| `RADIUMBOX_CONNECT_TIMEOUT_SECONDS` | `3` | External API connect timeout |

---

## 14. VPS Migration Checklist

### Phase 1 — Cloud VPS (same stack)

- [ ] Provision VPS (Ubuntu 22.04+ recommended)
- [ ] Install PHP 8.3+, Nginx/Apache, MySQL 8, Composer
- [ ] Clone/deploy application; copy `.env`
- [ ] Import MySQL backup
- [ ] Set `QUEUE_CRON_WORKER_ENABLED=true` (same as Hostinger initially)
- [ ] Configure system cron: `* * * * * php artisan schedule:run`
- [ ] Point DNS; enable SSL
- [ ] Verify `/up`, webhook endpoint, queue processing
- [ ] Run `php artisan infrastructure:metrics:collect`

### Phase 2 — Redis

- [ ] Install Redis
- [ ] Set `REDIS_HOST=127.0.0.1`
- [ ] Set `QUEUE_CONNECTION=redis`
- [ ] Set `CACHE_STORE=redis`
- [ ] Optionally `SESSION_DRIVER=redis`
- [ ] `php artisan optimize:clear && php artisan config:cache`
- [ ] Switch worker to `queue:work redis` under Supervisor
- [ ] Disable `QUEUE_CRON_WORKER_ENABLED` when Supervisor worker is stable

### Phase 3 — Horizon

- [ ] `composer require laravel/horizon`
- [ ] `php artisan horizon:install`
- [ ] Configure `config/horizon.php` supervisors
- [ ] Supervisor program: `php artisan horizon`
- [ ] Protect `/horizon` route
- [ ] Remove standalone `queue:work` Supervisor entries

### Phase 4 — Supervisor (if not using Horizon yet)

```ini
[program:radium-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/radium/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/radium/storage/logs/worker.log
stopwaitsecs=3600
```

### Phase 5 — Optional load balancer

- [ ] Multiple app servers behind LB
- [ ] Shared Redis for queue/cache/session
- [ ] Central MySQL (or managed DB)
- [ ] Sticky sessions if not using Redis sessions
- [ ] Health check: `GET /up`

**No application code changes required** for any phase — only environment, process manager, and infrastructure.

---

## 15. Migration Roadmap

```
Hostinger Shared Hosting
        │
        │  Same codebase, same MySQL schema
        │  Cron: schedule:run → queue:work --stop-when-empty
        ▼
Cloud VPS
        │
        │  QUEUE_CONNECTION still database OR early Redis
        │  Supervisor optional
        ▼
Redis
        │
        │  QUEUE_CONNECTION=redis
        │  CACHE_STORE=redis
        │  SESSION_DRIVER=redis
        ▼
Horizon
        │
        │  composer require laravel/horizon
        │  Replace queue:work with horizon
        ▼
Supervisor
        │
        │  Manages horizon / reverb / other daemons
        ▼
Optional Load Balancer
        │
        └─ Horizontal scaling; shared Redis + DB
```

---

## 16. Files Created (this task)

| File | Purpose |
|------|---------|
| `docs/infrastructure-readiness.md` | This document |
| `app/Infrastructure/Queue/QueueMetricsSnapshot.php` | Queue metrics DTO |
| `app/Infrastructure/Queue/QueueMetricsService.php` | Queue metrics collection |
| `app/Infrastructure/IntegrationHealth/Contracts/IntegrationHealthProbe.php` | Probe interface |
| `app/Infrastructure/IntegrationHealth/IntegrationHealthSnapshot.php` | Health DTO |
| `app/Infrastructure/IntegrationHealth/IntegrationHealthRegistry.php` | Probe registry |
| `app/Infrastructure/IntegrationHealth/Probes/CashfreeIntegrationHealthProbe.php` | Cashfree health |
| `app/Infrastructure/IntegrationHealth/Probes/RadiumBoxIntegrationHealthProbe.php` | RadiumBox health |
| `app/Infrastructure/IntegrationHealth/Probes/PlaceholderIntegrationHealthProbe.php` | Future integrations |
| `app/Providers/InfrastructureServiceProvider.php` | Service registration |
| `app/Console/Commands/CollectInfrastructureMetricsCommand.php` | Metrics CLI |

## 17. Files Modified (this task)

| File | Change |
|------|--------|
| `bootstrap/providers.php` | Register `InfrastructureServiceProvider` |
| `bootstrap/app.php` | Scheduler for cron queue worker + metrics |
| `.env.example` | Infrastructure env vars |
| `app/Jobs/RadiumBoxOrderEnrichmentJob.php` | Record queue processing metrics |
| `app/Services/RadiumBox/RadiumBoxOrderEnrichmentService.php` | Record integration health metrics |

## 18. Business Functionality

**No business logic, UI, or integration behavior was changed.**

Instrumentation hooks only write monitoring aggregates to cache and log existing events. Webhook processing, order creation, enrichment rules, and retry semantics are unchanged.

---

## Quick reference — enable production queue on Hostinger

1. Set `QUEUE_CRON_WORKER_ENABLED=true` in production `.env`
2. Add cron: `* * * * * cd /path/to/app && php artisan schedule:run`
3. Confirm jobs drain: `php artisan queue:work --stop-when-empty --max-time=55`
4. Monitor: `php artisan infrastructure:metrics:collect`
