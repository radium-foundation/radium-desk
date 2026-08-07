# LiteSpeed / PHP / OPcache Infrastructure Audit

**Status:** Investigate only (no config or code changes)  
**When:** 2026-08-07 · ~13:18–13:20 UTC  
**Host:** `desk.radiumbox.com` via `tools/config.sh` → `in-mum2-web2219.main-hosting.eu`  
**Deploy:** `e1370d76` `feat(workflow): protect manual ownership and optimize scheduler`  
**Canvas:** [`litespeed-php-infra-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/litespeed-php-infra-audit.canvas.tsx)

Related: [p0-lsphp-http-cpu-attribution-investigation.md](./p0-lsphp-http-cpu-attribution-investigation.md) · [radium-desk-performance-audit.md](./radium-desk-performance-audit.md) · [infrastructure-readiness.md](./infrastructure-readiness.md)

---

## Verdict

Application-level CPU work is largely optimized. Remaining Hostinger account CPU is still dominated by `lsphp` request workers. The two largest unused infrastructure levers are:

1. **`opcache.max_file_size=65536`** — every PHP file larger than 64KB is excluded from OPcache, including `bootstrap/cache/routes-v7.php`, `bootstrap/cache/config.php`, Composer autoload maps, Carbon, and `Illuminate\Database\Query\Builder`.
2. **Redis extension present but Redis not running** — `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION` all remain `database`, so every “cache hit” and session touch pays MySQL.

LSCache is not configured and is a weak fit for authenticated Desk HTML. Expect roughly **15–35%** further account-CPU relief from infrastructure alone (Redis + OPcache); the rest remains application path cost (`/admin/operations/live`, `/dashboard/live`).

**Overall infra score: ~56/100** (average of six layer scores below).

---

## Method and limits

| Source | Finding |
|--------|---------|
| SSH `ps` / `/proc/<pid>/environ` | Live LSAPI knobs on desk `lsphp` workers |
| `lsphp -i` / PHP selector `alt_php84.cfg` | OPcache / JIT / limits |
| `php artisan about` + `bootstrap/cache/*` | Laravel optimize state |
| Origin curl (`--resolve` to `187.127.183.72`) + Cloudflare edge | Cache-Control, Brotli, HTTP/2/3, LSCache headers |
| MariaDB via tinker | `cache` / `sessions` / `jobs` pressure |
| Not available | Web-worker OPcache hit rate (would need injected status script); Hostinger access logs; root LiteSpeed `httpd_config` |

No configuration or code was modified.

---

## Infrastructure scorecard

| Area | Grade | Score | Summary |
|------|:-----:|------:|---------|
| Laravel optimize caches | A | 92 | config / routes / events / views cached; composer classmap optimized |
| Static assets + CDN | B | 78 | Hashed Vite assets; CF HIT + Brotli/Gzip; missing `immutable` / long TTL |
| LiteSpeed / LSAPI / HTTP | B | 72 | ProcessGroup on; HTTP/2+3; Keep-Alive; `CHILDREN=180` oversized for desk traffic |
| PHP / OPcache | C | 48 | OPcache on but `max_file_size=64KB` excludes hot files; JIT off |
| LSCache | D | 25 | No package, no cache root, no `X-LiteSpeed-Cache`; HTML always DYNAMIC |
| Redis / session / queue | D | 22 | `ext-redis` present; Redis refused; all drivers on database |
| **Average** | — | **~56** | — |

---

## 1. LiteSpeed / LSAPI / HTTP

| Item | Observed |
|------|----------|
| Server | LiteSpeed (`Server: LiteSpeed`, `x-turbo-charged-by: LiteSpeed`) |
| Edge | Cloudflare (`server: cloudflare`, `cf-cache-status`) |
| SAPI | `lsapi V8.1 CloudLinux 1.3` via `/opt/alt/php84/usr/bin/lsphp` |
| PHP | 8.4.19 (web + CLI) |
| HTTP/2 | Yes (edge + origin) |
| HTTP/3 | Advertised (`alt-svc: h3=":443"`); client curl lacked HTTP/3 |
| Brotli / Gzip | Yes for static + HTML at edge; origin static CSS `content-encoding: br` |
| Keep-Alive | Default vhost: `timeout=5, max=100` |
| Process group | `LSPHP_ProcessGroup=on` |
| LSAPI_CHILDREN | **180** |
| LSAPI_MAX_IDLE_CHILDREN | 90 |
| LSAPI_EXTRA_CHILDREN | 90 |
| LSAPI_MAX_IDLE / PGRP_MAX_IDLE | 600 |
| LSAPI_MAX_PROCESS_TIME | 180 |
| Desk concurrency (probe) | 2–4 `lsphp:ins/desk.radiumbox.com` workers; ΣCPU bursts ~30–117 |

**Assessment:** Reasonable shared-hosting defaults. Process group mode is correct. `LSAPI_CHILDREN=180` is far above observed desk concurrency and can amplify memory/LVE pressure if many long requests pile up (e.g. heavy Operations Live). Enterprise conf under `/usr/local/lsws/conf` is not readable from the account.

---

## 2. LSCache

| Check | Result |
|-------|--------|
| LiteSpeed Cache Laravel package | Not installed |
| `~/lscache` / public cache root | Absent |
| `.htaccess` cache rules | None (stock Laravel rewrite only) |
| Origin `X-LiteSpeed-Cache*` | Absent |
| HTML `Cache-Control` | `no-cache, private` (login/up) |
| CF status for HTML | `DYNAMIC` |
| Public / private / ESI | Not in use |
| Browser cache (HTML) | Disabled (correct for auth app) |
| Miss ratio (dynamic) | Effectively **100%** |

**Assessment:** LSCache is not enabled for Desk. For an authenticated operator app with sessions and CSRF, full-page public cache is mostly inappropriate. Private cache / ESI could theoretically fragment shells, but complexity is high vs Redis + OPcache + remaining app work. Optional later: cache only anonymous `/login` and pure static.

Server-wide `webcachemgr` addon exists under `/usr/local/lsws/add-ons/webcachemgr` but is not wired for this vhost from the account’s perspective.

---

## 3. PHP / OPcache

### Settings (PHP 8.4 selector + `lsphp -i`)

| Setting | Value |
|---------|------:|
| `opcache.enable` | On |
| `opcache.memory_consumption` | 128M |
| `opcache.max_accelerated_files` | 10000 |
| `opcache.interned_strings_buffer` | 8 |
| `opcache.validate_timestamps` | On |
| `opcache.revalidate_freq` | 2 |
| `opcache.max_file_size` | **65536** |
| `opcache.jit` | **disable** |
| `opcache.jit_buffer_size` | 64M (unused while disabled) |
| `opcache.preload` | empty |
| `opcache.file_cache` | empty |
| `realpath_cache_size` / `ttl` | 4096K / 120 |
| `memory_limit` | 512M |
| `max_execution_time` | 300 |
| `zlib.output_compression` | Off (LiteSpeed/CF compress instead) |

### Critical: `max_file_size=65536`

Approx. **27–29** PHP files in the deploy tree exceed 64KB and are **not** eligible for OPcache. Hot-path exclusions include:

| File | Size |
|------|-----:|
| `vendor/composer/autoload_static.php` | 949 KB |
| `vendor/composer/autoload_classmap.php` | 860 KB |
| `bootstrap/cache/routes-v7.php` | 377 KB |
| `vendor/nesbot/carbon/.../CarbonInterface.php` | 444 KB |
| `vendor/nesbot/carbon/.../Carbon.php` | 291 KB |
| `vendor/laravel/.../Query/Builder.php` | 149 KB |
| `bootstrap/cache/config.php` | 134 KB |
| `vendor/laravel/.../Eloquent/Model.php` | 80 KB |
| `vendor/symfony/http-foundation/Request.php` | 78 KB |

App+vendor PHP file count ≈ **7916** vs `max_accelerated_files=10000` (tight headroom once large files are admitted).

### CLI OPcache sample (not web SHM)

After `artisan tinker`: ~1294 scripts, ~34 MB used, 0 wasted, JIT off. Web workers use separate shared memory; hit rate was **not** measured without injecting a status endpoint.

### Extensions (relevant)

`Zend OPcache`, `redis`, `igbinary`, `msgpack`, `imagick`, `sodium`, `pdo_mysql`, `intl`, …

---

## 4. Static assets

| Asset class | Cache-Control | Compression | CF |
|-------------|---------------|-------------|-----|
| `/build/assets/*.css` (hashed) | Origin `public, max-age=604800`; edge often `691200` | br / gzip | HIT after warm |
| `/build/assets/*.js` (hashed) | `public, max-age=691200` (edge) | br | MISS→HIT |
| `/build/assets/*.woff2` | `public, max-age=691200` | — | HIT |
| `/brand/*` | `public, max-age=604800` | — | HIT |
| HTML (login) | `no-cache, private` | br at edge | DYNAMIC |

**Gaps:** no `immutable`; TTL is 7–8 days instead of 1 year for content-hashed Vite filenames. Build tree: **25** files / **~1.6 MB**.

---

## 5. Laravel optimization

| Check | Status |
|-------|--------|
| `config.php` | Present (cached) |
| `routes-v7.php` | Present (cached) |
| `events.php` | Present (cached) |
| Views compiled | 531 files / 5.9 MB |
| `composer optimize-autoloader` | Yes (`optimize-autoloader: true`; classmap ~6673) |
| Deploy path | `composer install --no-dev --optimize-autoloader` + `artisan optimize` |
| Laravel | 13.17.0 |
| `APP_DEBUG` | false |
| `public/storage` | **NOT LINKED** (functional note, not CPU) |

Laravel framework caches are in good shape. Ironically, the largest cached PHP artifacts (`routes-v7.php`, `config.php`) are then excluded from OPcache by `max_file_size`.

---

## 6. Infrastructure drivers / filesystem

| Driver | Production value | Notes |
|--------|------------------|-------|
| Cache | `database` | 946 rows · ~12 MB; top key `operator.dashboard.snapshot:v2` ~8.2 MB |
| Session | `database` | 1509 rows; 198 sessions / 15m; **3** auth users / 15m |
| Queue | `database` | 2 pending; `QUEUE_WORKER_MODE=dedicated_cron` |
| Broadcast | `ably` | healthy path from prior probes |
| Redis | `127.0.0.1:6379` | **Connection refused** |
| Filesystem | local disk | App tree ~211 MB; no inode pressure (23%) |

Database cache remains a hidden MySQL tax on every intended cache hit (see prior Laravel cache investigation).

---

## Misconfigurations (ranked)

| Severity | Finding | Effect |
|----------|---------|--------|
| Critical | `opcache.max_file_size=65536` | Hot Laravel/Carbon/route/config files never bytecode-cached |
| Critical | Redis down / unused | Cache + session + queue stay on MySQL |
| High | LSCache not wired | No edge HTML cache (low value for auth app) |
| High | JIT disabled | Leaves CPU on Blade-heavy paths after OPcache fix |
| Medium | `LSAPI_CHILDREN=180` | Oversized vs observed 2–4 desk workers |
| Medium | Asset TTL / no `immutable` | Extra origin/CF fetches after 7–8 days |
| Low | `expose_php=On` | `X-Powered-By` info leak |
| Low | `public/storage` not linked | Functional gap if public disk URLs expected |

---

## CPU impact estimate (infra-only)

Estimated share of **current** lsphp/account CPU proxy that each fix can remove. Ranges overlap — do not sum naively.

| Fix | Low | High |
|-----|----:|-----:|
| `opcache.max_file_size → 0` (or ≥2M) | 5% | 15% |
| Redis for cache (+ session) | 10% | 20% |
| JIT after OPcache fix | 5% | 12% |
| `max_accelerated_files` + interned strings | 2% | 6% |
| `validate_timestamps=0` (+ deploy reset) | 1% | 3% |
| Asset `immutable` + 1y | 0.5% | 2% |
| LSCache anonymous only | 0.5% | 2% |
| **Combined realistic band** | **~15%** | **~35%** |

Remainder stays in application PHP/SQL (Operations Live, dashboard live HTML, etc.).

---

## Priority ranking

| Pri | Action | CPU impact | Where |
|-----|--------|------------|-------|
| **P0** | Set `opcache.max_file_size=0` (or ≥2M); recycle lsphp | 5–15% | hPanel PHP 8.4 selector |
| **P0** | Provision Redis; `CACHE_STORE=redis` then `SESSION_DRIVER=redis` | 10–20% | Hostinger Redis / VPS |
| **P1** | `max_accelerated_files≥20000`, `interned_strings_buffer` 32–64 | 2–6% | PHP selector |
| **P1** | Evaluate JIT (tracing) after OPcache fix; soak test | 5–12% | PHP selector |
| **P2** | `/build/assets/` → `public, max-age=31536000, immutable` | Low origin | `.htaccess` / CF rule |
| **P2** | `validate_timestamps=0` in prod + deploy-time opcache reset | 1–3% | PHP selector + `desk deploy` |
| **P3** | LSCache for `/login` + static only | Low | hPanel LSCache |
| **P3** | VPS: worker caps, Horizon, Redis queue, access logs | Structural | Migration |

---

## Quick wins

1. **`opcache.max_file_size=0`** in CloudLinux PHP 8.4 selector (`~/.cl.selector/alt_php84.cfg` currently pins `65536`).
2. Raise **`max_accelerated_files`** to **20000** and **`interned_strings_buffer`** to **32–64**.
3. Add long-cache **`immutable`** headers for hashed `/build/assets/*`.
4. Set **`expose_php=Off`**.

## Long-term improvements

1. Run Redis; move cache then session (queue can follow on VPS/Horizon).
2. Soak-test JIT; pair with `validate_timestamps=0` and explicit opcache reset on deploy.
3. Migrate off shared Hostinger for tunable LSAPI children, URI-level access logs, and supervised workers.
4. Continue application work on `/admin/operations/live` — still the top HTTP CPU consumer after infra.

---

## Production snapshot (probe window)

| Metric | Value |
|--------|------:|
| Load average | 15.7–16.9 |
| HEAD | `e1370d76` |
| Auth users (15m) | 3 |
| Guest/bot sessions (15m) | ~195 |
| Desk lsphp workers | 2–4 |
| Cache table | 946 rows / ~12 MB |
| Redis | Connection refused |

---

## What not to do

- Do **not** enable blanket public LSCache on authenticated Desk routes.
- Do **not** enable JIT before fixing `max_file_size` (measure after OPcache is actually caching hot files).
- Do **not** assume Cloudflare HTML caching will help — HTML is correctly private/DYNAMIC.
