# MySQL `SQLSTATE[HY000] [2002] Operation not permitted` — Production Investigation

**Status:** Read-only investigation (no code or production changes)  
**Captured:** 2026-08-07 ~11:45 IST  
**Host:** Hostinger shared (`in-mum2-web2219.main-hosting.eu`) · LiteSpeed `lsphp` · CloudLinux LVE · MariaDB 11.8.8  
**Log window:** `storage/logs/laravel.log` · 2026-07-28 → 2026-08-07  

**Canvas:** [`mysql-2002-operation-not-permitted-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/mysql-2002-operation-not-permitted-investigation.canvas.tsx)

---

## Root cause

Hostinger enforces a **per-account MySQL new-connection rate limit (~20 new connections per second)**. When LiteSpeed PHP workers open more fresh PDO connections than allowed in one second, `connect()` fails with OS errno **EPERM**, which PDO surfaces as:

```text
SQLSTATE[HY000] [2002] Operation not permitted
```

This matches [Hostinger’s official support article](https://www.hostinger.com/support/how-to-fix-mysql-operation-not-permitted-error/) for this exact error string.

MariaDB itself was **not** down during failures. Concurrent CLI/queue workers using the same credentials continued to run successfully.

---

## Timeline (IST)

| When | ERROR events | Notes |
|------|-------------:|-------|
| 2026-07-28 12:50:35 | 1 | First event in current `laravel.log` |
| 2026-07-30 13:07:09 | 2 | Same second / same session |
| 2026-08-03 02:34:12 | 2 | Overnight |
| 2026-08-03 16:14:34 | 1 | Afternoon single |
| 2026-08-04 13:52:26 | 1 | — |
| 2026-08-04 18:38:16–32 | 6 | Evening cluster (~16s) |
| **2026-08-06 18:16:26** | **35** | Largest burst (mostly one session ID) |
| **2026-08-07 10:23:43–10:24:13** | **7** | Queue worker DB jobs OK in same window |

**Totals:** 55 `production.ERROR` events · 12 distinct seconds · 14 distinct session IDs · ~10 calendar days in log.

### Correlation with MySQL availability

At **2026-08-07 10:23–10:24**:

- `laravel.log`: seven HTTP 2002 failures on `select * from sessions …`
- `queue-worker.log`: `RadiumBoxOrderEnrichmentJob` RUNNING → DONE (database-backed)

**Conclusion:** MySQL was available; Hostinger denied additional **new** connects from the web path under rate pressure.

Unrelated: “MySQL server has gone away” appears only on **2026-07-20**, not during these 2002 bursts.

---

## Frequency

- **Sparse** overall: a handful of seconds across ~10 days.
- **Spike-shaped**: one second accounted for 35/55 events (2026-08-06 18:16:26).
- Parallel browser requests / retries sharing one session cookie amplify the connect rate (top session ID alone dominated the Aug 6 burst).

---

## Session-only vs all DB queries

| Observation | Meaning |
|-------------|---------|
| 55/55 logged SQL statements are `select * from sessions where id = …` | `StartSession` is the first DB consumer when `SESSION_DRIVER=database` |
| Failure site is `Connectors/Connector.php` (PDO construct) | Connect fails **before** any SQL runs |
| 55/55 stacks include `public_html/index.php`; 0 include `artisan` | Web/`lsphp` only in this log window |
| Cron/queue logs: **0** matches for 2002 | CLI path not rate-limited the same way / lower connect churn |

**Verdict:** Not a broken `sessions` table. During a burst, **all new HTTP DB connections fail**; only the session read appears in the error SQL because nothing later in the request runs. Concurrent CLI DB work can still succeed.

---

## Config audit (production)

| Item | Value | Implication |
|------|-------|-------------|
| `DB_HOST` | `localhost` | mysqlnd uses Unix socket `/var/lib/mysql/mysql.sock` |
| `DB_SOCKET` | unset / empty | Default socket path |
| PDO `ATTR_PERSISTENT` | unset | Fresh connection every request |
| `SESSION_DRIVER` | `database` | Every stateful HTTP request opens MySQL immediately |
| `CACHE_STORE` | `database` | Extra MySQL usage |
| `QUEUE_CONNECTION` | `database` | Cron workers also connect |
| Retries / failover | none | Immediate request failure |

### Limits observed (read-only probe)

| Limit | Value | Role |
|-------|-------|------|
| Hostinger new-connect rate | ~20/sec (vendor doc) | **Primary cause of this error** |
| `max_user_connections` | 100 | Separate; would surface as max_user_connections / 1226 |
| `max_connections` (server) | 2000 | Shared MariaDB |
| `Max_used_connections` | ~930 | Historical server peak |
| `Threads_connected` (probe) | 160 | Server-wide at investigation |
| This app `PROCESSLIST` | 4 | Healthy at probe |

Live probes at investigation time: `localhost`, `127.0.0.1`, and Unix socket all **OK**.

### localhost vs 127.0.0.1

Secondary factor only. Both transports succeed when under the rate limit. Changing host alone does **not** remove Hostinger’s new-connection throttle.

### LiteSpeed / CloudLinux

- Active `lsphp` workers for `desk.radiumbox.com`
- `/proc/lve` present (CloudLinux)
- Fits bursty web connect pattern under shared-hosting controls

---

## Recommendations (not applied)

### Safest long-term

1. **Move session + cache off MySQL** (`SESSION_DRIVER=redis`, `CACHE_STORE=redis`) when Redis is available — already on the Hostinger→VPS readiness path in `docs/infrastructure-readiness.md`. This cuts new MySQL connects per HTTP request dramatically.
2. **Eventually leave shared Hostinger MySQL** (VPS / dedicated DB) so the ~20/sec new-connection rate limit no longer applies.

### Near-term (Hostinger official band-aid)

3. Enable **PDO persistent connections** (`PDO::ATTR_PERSISTENT => true`) on the Laravel mysql connection (env-gated), per Hostinger’s article.  
   **Caveat:** watch `max_user_connections=100` — many long-lived `lsphp` workers can hold sockets. Roll out with monitoring.

### Hygiene

4. Optionally set `DB_HOST=127.0.0.1` for explicit TCP (does not fix rate limit by itself).
5. Alert on `laravel.log` matching `[2002] Operation not permitted` — treat as hosting rate-limit, not application SQL.

---

## What was ruled out

| Hypothesis | Status |
|------------|--------|
| MySQL / MariaDB fully down | Ruled out (CLI success during bursts) |
| Wrong DB credentials | Ruled out (steady-state connects OK) |
| Sessions table corruption | Ruled out (fail at connect, not query plan) |
| `max_connections` exhaustion | No matching “Too many connections” for these bursts |
| Persistent connection leaks today | N/A — persistence is **not** enabled |
| Laravel failover misconfig | N/A — no failover configured |

---

## Sources

- Production `laravel.log` / `queue-worker.log` (SSH read-only)
- Production `.env` non-secret keys + `config/database.php` / `config/session.php`
- Live `SHOW VARIABLES` / `SHOW STATUS` / `SHOW PROCESSLIST`
- [Hostinger: How to fix MySQL “Operation not permitted” error](https://www.hostinger.com/support/how-to-fix-mysql-operation-not-permitted-error/)
