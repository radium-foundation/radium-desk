# Redis Migration Readiness Assessment

**Date:** 2026-08-07  
**Project:** Radium Desk  
**Production HEAD:** `e1370d7` (confirmed local `main`)  
**Scope:** Investigation only — no migration, no code changes  
**Canvas:** [`redis-migration-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/redis-migration-readiness.canvas.tsx)

**Related docs:** [infrastructure-readiness.md](./infrastructure-readiness.md) · [radium-desk-performance-audit.md](./radium-desk-performance-audit.md) §5 · [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) · prior cache tax canvas [`p0-laravel-cache-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-laravel-cache-investigation.canvas.tsx)

---

## Verdict

**Ready for Redis cache cutover with env + ops changes.** Session and queue can follow in phased order. The application already uses Laravel facades end-to-end; Redis connection blocks exist in config; **no cache tags** and **no Redis-specific app code** block migration.

**Not ready for a blind all-at-once cutover** until:

1. Redis server + `phpredis` (`ext-redis`) are available on the host  
2. Hardcoded `queue:work database` in `QueueRouting` / recovery tooling / Hostinger Cron #2 is updated when queue moves  
3. Cutover runbook covers cold-cache spike, session invalidation, and schedule mutex clear  

**Horizon is optional Phase 3** — package not installed; not required for cache/session Redis.

---

## 1. Current drivers (production posture)

| Concern | Env key | Config default | `.env.example` | Production (2026-08-07) |
|---------|---------|----------------|----------------|-------------------------|
| Cache | `CACHE_STORE` | `database` | `database` | **`database`** (confirmed) |
| Session | `SESSION_DRIVER` | `database` | `database` | **`database`** |
| Queue | `QUEUE_CONNECTION` | `database` | `database` | **`database`** |
| Failed jobs | `QUEUE_FAILED_DRIVER` | `database-uuids` | (implicit) | MySQL `failed_jobs` |
| Redis client | `REDIS_CLIENT` | `phpredis` | `phpredis` | Configured, unused |
| Horizon | — | — | `QUEUE_WORKER_MODE` lists `horizon` | **Not installed** |

Notes:

- Laravel 11+ uses `CACHE_STORE`, not `CACHE_DRIVER`. No `CACHE_DRIVER` references in the repo.
- Redis stores already defined: cache connection DB `1`, default/queue DB `0` (`config/database.php`).
- `predis/predis` is **not** in composer — runtime requires **phpredis**.
- Tests use `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync` — do not simulate production Redis serialization.

---

## 2. Inventory by investigation item

### 2.1 Cache driver

- Default store: `database` → tables `cache` + `cache_locks`.
- Redis store ready: `config/cache.php` → `connection=cache`, `lock_connection=default`.
- **~69** `app/` files call `Cache::` (all default store; no `Cache::store('file')` in app code).
- `PlatformCachePolicy` and `.env.example` already document Redis as required for Platform ECC.

### 2.2 Session driver

- `SESSION_DRIVER=database` → `sessions` table.
- JSON serialization; standard auth regenerate/invalidate.
- No app code assumes database sessions specifically.
- Redis: set `SESSION_DRIVER=redis` (+ optional `SESSION_CONNECTION`).

### 2.3 Queue driver

- `QUEUE_CONNECTION=database` → `jobs` / `job_batches` / `failed_jobs`.
- Named queues: `critical`, `notifications`, `default`, `maintenance`.
- Jobs: `RadiumBoxOrderEnrichmentJob` (unique), driver-guide jobs, `ScanWorkRecognitionMonthJob`.
- Redis queue block exists in `config/queue.php`.
- **Gap:** `QueueRouting::scheduledWorkerCommand()` and `ProductionRecoverQueuesCommand` hardcode `queue:work database`. Hostinger Cron #2 also hardcodes `database`.

### 2.4 Cache tags

- **Zero usage** of `Cache::tags()` / tagged invalidation in application code.
- No tag-driver compatibility risk (database/file do not support tags; Redis would, but unused).

### 2.5 Locks

All operational locks use `Cache::lock()` on the default cache store:

| Area | Examples |
|------|----------|
| Platform warmers | `platform:warm:lock:{warmer}` |
| Gmail sync | `gmail-inbound-sync:*` |
| Email case create | `inbound_email:auto_sc:*` |
| IRA telegram batches | `ira_assignment_batch:*` |
| Bonvoice auth | `bonvoice.api.auth_refresh` |
| Repair | `system_repair:*` |
| Deferred smart assignment | batch process lock |
| Scheduler | `withoutOverlapping()` → cache mutex (~25 tasks) |
| Unique jobs | `RadiumBoxOrderEnrichmentJob` (`ShouldBeUnique`, cache-backed) |

`PayrollMonthLock` is a **DB model**, not a cache lock — unaffected.

### 2.6 Rate limiters

| Mechanism | Backend today |
|-----------|---------------|
| Login `RateLimiter` (5 / email+IP) | Default cache store |
| `throttle:6,1` on presence heartbeat | Default cache store |
| Password broker throttle | Auth token table (not cache) |
| Team activity write throttle | Config/DB logic (not RateLimiter) |

Redis cutover preserves behavior; counters reset at cutover.

### 2.7 Horizon compatibility

| Item | Status |
|------|--------|
| `laravel/horizon` | **Not in composer** |
| `config/horizon.php` | **Missing** |
| `QueueWorkerMode::Horizon` | Enum placeholder only |
| Docs Phase 3 | Install after Redis queue |

**Compatible later** once `QUEUE_CONNECTION=redis` + package install. Not a blocker for cache-only Redis.

### 2.8 Scheduler compatibility

- Mutexes live on active cache store — move cleanly to Redis.
- Heartbeat intentionally lock-free; durable JSON fallback under `storage/framework/platform-health/`.
- At cutover: `php artisan schedule:clear-cache` to avoid stale mutex confusion across stores.
- In-schedule `queue:work` only when `QUEUE_WORKER_MODE=scheduler` — still hardcodes `database` today.

### 2.9–2.12 Domain compatibility

| Domain | Cache / queue / locks today | Redis ready? | Risk | Required changes |
|--------|----------------------------|--------------|------|------------------|
| **Automation** | Snapshot/meta/dirty in cache; schedule overlap mutex; outbox in-process | Yes | Low | Env (`CACHE_STORE`) |
| **Live Operations** | Dashboard/ops/IRA short TTL caches; broadcasts via Reverb/Ably (independent) | Yes | Low | Env; optional Reverb Redis scaling later |
| **Finance** | Balance/overview caches; journal `lockForUpdate()` is DB | Yes | Low | Env |
| **Email workspace** | Widget TTL, forever read-state, OAuth tokens, mailbox locks, metrics increments | Yes | Low–Med | Env; monitor forever-key memory |
| **Platform health** | Cache fast path + file durable fallback; warm locks | Yes (recommended) | Low | Env; keep file fallback |
| **Communication actions** | Platform overview cache; driver-guide via `notifications` queue | Yes | Low | Cache env; queue worker fix when queue moves |
| **Coalescers / batchers** | Request-scoped coalescer; invalidates cache; dispatches batch jobs | Yes | Low–Med | Queue worker command when queue moves |

### 2.13 Database cache usage

- Tables: `cache`, `cache_locks` (migration `0001_01_01_000001_create_cache_table.php`).
- Estimated **~20–35%** of MySQL QPS from cache tables (post Phases 1–8).
- Quiet minute: **~15–40** pure cache SQL ops (heartbeat, presence, locks, warm skips).
- Hot namespaces: `platform:*`, `operator.dashboard.*`, `operations:*`, `ira:*`, `automation.operations.*`, `app.settings.all`, metrics counters.

### 2.14 File cache usage

- Laravel `file` store configured but **unused** as default; no app `Cache::store('file')`.
- `storage/framework/cache/data` effectively empty (gitignore stubs only).
- Separate non-cache file: platform health heartbeats JSON (intentional durable path).

---

## 3. Compatibility report (summary)

| Capability | Works on Redis? | Code change required? |
|------------|-----------------|------------------------|
| Cache get/put/remember/forget/increment | Yes | No (env) |
| Cache tags | N/A (unused) | No |
| Cache locks + schedule mutex + unique jobs | Yes | No (env); clear schedule cache at cutover |
| Rate limiters | Yes | No (env) |
| Sessions | Yes | No (env); users re-login unless migrated |
| Queue | Yes | **Yes** — stop hardcoding `database` in worker/cron/recovery |
| Horizon | Future | Package + Supervisor |
| Broadcasting (Ably/Reverb) | Independent of cache | Reverb multi-node needs Redis separately |
| Domain features (Automation, Live Ops, Finance, Email) | Yes | Env; memory watch on forever keys |

**Overall readiness: GO for cache-first Redis; CONDITIONAL GO for session; CONDITIONAL GO for queue (after worker command / cron update).**

---

## 4. Required changes (before / during migration)

Investigation-only — listed for planning, **not implemented**.

### Must have (cache cutover)

1. Redis server reachable (`REDIS_HOST` / `REDIS_PORT` / password).
2. PHP `ext-redis` (phpredis) installed — no predis fallback.
3. `.env`: `CACHE_STORE=redis`, Redis DBs configured (`REDIS_DB=0`, `REDIS_CACHE_DB=1`).
4. `php artisan config:cache` (or `optimize:clear` then config cache).
5. `php artisan schedule:clear-cache`.
6. Verify Platform `CacheHealthProvider` probe /admin/platform.

### Must have (queue cutover)

1. Drain `jobs` table (or accept loss of pending DB jobs).
2. Change hardcoded worker strings to `redis` (or `config('queue.default')`) in:
   - `app/Infrastructure/Queue/QueueRouting.php`
   - `app/Console/Commands/ProductionRecoverQueuesCommand.php`
   - Hostinger Cron #2 (and any ops runbooks)
3. `.env`: `QUEUE_CONNECTION=redis`.
4. Worker: `queue:work redis --queue=critical,notifications,default,maintenance ...`.

### Recommended (session)

1. Low-traffic window: `SESSION_DRIVER=redis`.
2. Expect full re-login unless sessions are migrated (usually not worth it).

### Later (Horizon)

1. `composer require laravel/horizon` + install + Supervisor.
2. `QUEUE_WORKER_MODE=horizon`.

---

## 5. Migration risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Cold cache → temporary MySQL/CPU spike while Platform/dashboard/automation rebuild | **High** (transient) | Cut over at quiet hour; ensure warmers run; watch Hostinger CPU 15–30 min |
| Hardcoded `queue:work database` after `QUEUE_CONNECTION=redis` | **High** | Fix code + cron before/at queue cutover; smoke-test job drain |
| Pending jobs stranded in MySQL `jobs` | **Medium** | Drain before switch |
| All users logged out on session switch | **Medium** | Announce / off-hours |
| Stuck schedule mutex perception across store change | **Medium** | `schedule:clear-cache` |
| Missing phpredis | **High** | Preflight `php -m \| grep redis` |
| Redis OOM / eviction of forever keys (settings, email read-state, tokens) | **Medium** | Memory limits; maxmemory-policy; monitor key growth |
| Shared Redis prefix collision with other apps | **Low–Med** | Distinct `REDIS_PREFIX` / `CACHE_PREFIX` |
| Horizon mode set without package | **Low** | Do not set until installed |
| Test suite does not prove Redis production behavior | **Low** | Staging smoke with `CACHE_STORE=redis` |

---

## 6. Rollback strategy

| Layer | Rollback | Notes |
|-------|----------|-------|
| Cache | Set `CACHE_STORE=database`, `config:cache` | Redis keys abandoned; DB `cache` table may be stale/empty — warmers refill |
| Session | Set `SESSION_DRIVER=database` | Redis sessions abandoned; users re-login again |
| Queue | Set `QUEUE_CONNECTION=database`; restore cron to `queue:work database` | Drain Redis queues first if jobs enqueued there; failed_jobs stay in MySQL |
| Config | Keep prior `.env` snapshot | Fastest rollback artifact |
| Code | N/A if cache-only env change | Queue worker string changes need deploy revert or dual-read command |

**Rollback order (incident):** cache first (largest blast radius for Platform) → session → queue. Do not flush MySQL cache tables during Redis incident unless intentionally forcing rebuild.

**Keep available during cutover window:** previous `.env`, Cron #2 command, and ability to run `schedule:clear-cache`.

---

## 7. Production deployment strategy

### Recommended phases

| Phase | What | Downtime | Benefit |
|-------|------|----------|---------|
| **P0 — Preflight** | Install Redis + phpredis; connectivity probe; sample prod `cache` hot keys/sizes | None | Confirm infra |
| **P1 — Cache only** | `CACHE_STORE=redis`; clear schedule cache; config cache; watch Platform + live | Seconds (config) | Primary SQL/CPU win |
| **P2 — Session** | `SESSION_DRIVER=redis` off-hours | Brief re-login | Removes session SQL (often large share of request SQL) |
| **P3 — Queue** | Drain jobs → code/cron fix → `QUEUE_CONNECTION=redis` → worker smoke | Minutes coordinated | Removes jobs table contention |
| **P4 — Horizon (optional)** | Install Horizon + Supervisor | Deploy window | Ops UX + supervised workers |
| **P5 — Tune** | Raise safe short TTLs; reduce increment amplification; remeasure | None | Second-order gains |

### Cutover checklist (P1 cache)

1. [ ] Redis up; `redis-cli PING`
2. [ ] `php -m` shows `redis`
3. [ ] Snapshot `.env`
4. [ ] Set `CACHE_STORE=redis` (+ host/auth)
5. [ ] `php artisan optimize:clear && php artisan config:cache`
6. [ ] `php artisan schedule:clear-cache`
7. [ ] Hit `/admin/platform` — cache health green
8. [ ] Confirm live dashboard + automation snapshot after one warm cycle
9. [ ] Watch MySQL QPS and account CPU 30 minutes
10. [ ] Rollback path ready (`CACHE_STORE=database`)

### Do not

- Enable Horizon without package.
- Switch queue without updating Cron #2 / `QueueRouting`.
- Rely on file cache migration (nothing to migrate).
- Expect tests (`array` cache) to validate Redis cutover.

---

## 8. Estimated production benefit

Estimates from prior production-correlated audits (Phases 1–8 complete; `CACHE_STORE=database` measured 2026-08-07). **Cache-only Redis** unless noted. No business-logic change assumed.

| Metric | Today (database cache) | Likely after Redis cache | Notes |
|--------|------------------------|--------------------------|-------|
| **CPU** | ~8–18% of account CPU from cache I/O + serialize | **~10–20% account-wide reduction** | Does not remove cold zone rebuild CPU |
| **SQL reduction** | Cache tables ~20–35% of MySQL QPS | **~25–40% overall SQL**; **70–90%** on warm-skip paths; **5–15%** on cold rebuild minutes | 100% of `cache`/`cache_locks` queries removed |
| **Cache hit rate** | “Hits” still hit MySQL | **True memory hits**; effective hit latency drops sharply | Logical hit rate similar; physical cost collapses |
| **Response time** | Cache-heavy admin/ops pay SQL RTT per key | **~10–25%** cache-heavy surfaces; **~5–15%** warm live | Platform zones benefit most |
| **Concurrent users** | DB cache + sessions contend with business SQL | **Noticeably higher headroom** on shared Hostinger | Session Redis (P2) adds further headroom by removing `sessions` SELECTs |

### Session + queue add-ons (directional)

| Add-on | Extra benefit |
|--------|---------------|
| Session → Redis | Large cut in per-request session SQL (historically dominant in some MySQL incident traces) |
| Queue → Redis | Lower job table lock/contention under enrichment/driver-guide bursts; enables Horizon |
| TTL raise after Redis (P5) | Further poll/CPU reduction on short-TTL dashboard/ops keys |

### What Redis does **not** fix

- Cold `platform:snapshots:warm` business SQL/compute (~9s class ticks already measured).
- Live HTML poll fan-out / Team Activity build cost.
- Missing Horizon package (ops concern, not performance of cache).

---

## 9. Migration phases (concise roadmap)

```
P0 Preflight     → Redis + phpredis + hot-key sample
P1 Cache         → CACHE_STORE=redis          ★ primary win
P2 Session       → SESSION_DRIVER=redis       (off-hours)
P3 Queue         → code/cron + QUEUE_CONNECTION=redis
P4 Horizon       → optional supervised workers
P5 Tune          → TTL / metrics / remeasure
```

Aligns with [infrastructure-readiness.md](./infrastructure-readiness.md) Phases 2–3.

---

## 10. Deliverables checklist

| Deliverable | Status |
|-------------|--------|
| Readiness report | This document |
| Compatibility report | §3 + domain table §2.9–2.12 |
| Risks | §5 |
| Required changes | §4 |
| Migration phases | §7 / §9 |
| Estimated production benefit | §8 |
| Interactive canvas | [redis-migration-readiness.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/redis-migration-readiness.canvas.tsx) |

**Explicit non-goals completed:** no Redis migration performed; no application code modified for this assessment.
