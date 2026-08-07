# P0 CPU Incident — Production Remeasure

**Status:** Read-only production measurement (no code changes)  
**When:** 2026-08-07 · ~06:57–07:02 UTC  
**Host:** `desk.radiumbox.com` via `tools/config.sh` (SSH port 65002)  
**Canvas:** [`p0-cpu-incident-remeasure.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-cpu-incident-remeasure.canvas.tsx)

---

## Verdict

**Phase 1 assumptions are partially wrong for today’s incident.**

| Assumption | Production fact |
|------------|-----------------|
| `/dashboard/live` still dominates via 5k–8k SQL | **False.** Warm live is **7 queries**. Phase 1 **is deployed** (`30daa64`). |
| Phase 2 Interakt enqueue-only is live | **False.** Controller still calls `OutboxProcessorService::process()`. |
| Fixing live + Interakt would clear 100% CPU | **Incomplete.** Account CPU is now **cron-dominated**, led by `platform:snapshots:warm` (~11.6s @ ~85% CPU **every minute**). |

Host load remained **22–27** during measurement (shared Hostinger node — other tenants contribute to load average). Our account still shows sustained high `%CPU` on artisan + lsphp.

---

## Deploy / runtime facts

| Fact | Value |
|------|------:|
| Git HEAD | `30daa64` `perf(dashboard): optimize live dashboard request pipeline` |
| Phase 1 (`SettingService` memo) | **Present** |
| Phase 2 (Interakt enqueue-only) | **Absent** — still `process()` after outbox write |
| Sessions 15m / 60m | 7 / 7 |
| Active incidents | 1059 |
| Ably | `connected`, `polling_active=false` |
| Cache / queue | `database` / `database` |
| Reverb / Horizon / Supervisor daemons | **None** (queue via `schedule:run` → `queue:work`) |
| Outbox | pending 12 · processing 4 (Cashfree) · failed 2778 · completed 333752 |

---

## TOP 20 CPU consumers (estimated % of **account** work)

Ranked by **cost = count/min × avg wall-ms** (CPU-time proxy). Percents normalized across this TOP20.

| # | Consumer | Est. % | Cost units | Evidence |
|--:|----------|-------:|-----------:|----------|
| 1 | `platform:snapshots:warm` (cron every 1m) | **28%** | 11613 | **11613 ms** wall; process sample **72–88% CPU** for 14s+ each minute |
| 2 | `automation:snapshot` (cron every 1m) | **16%** | 6607 | **6607 ms** wall; mutex; ~65–80% CPU while running |
| 3 | `queue:work` (cron every 1m, `--max-time=55`) | **12%** | ~5000 | Top-of-minute **59% CPU**; heavy `RadiumBoxOrderEnrichmentJob` |
| 4 | `POST /api/webhooks/interakt` + sync `process()` | **12%** | ~5100 | Phase 2 missing; rpm ~3.4 (peak 16/min); lag avg **23s**, max **238s** |
| 5 | `watchdog:send-critical-alerts` (every 5m) | **10%** | 4242 | **21208 ms** wall amortized |
| 6 | `GET /dashboard/live` | **9%** | 3540 | avg **590 ms** (342–1066); **7 SQL** warm; payload **~514 KB** |
| 7 | `schedule:run` orchestration | **4%** | ~2000 | Stays alive ~16s while children run |
| 8 | `GET /notifications/poll` | **3%** | 1428 | ~21/min × ~68 ms; 6 SQL |
| 9 | `inbound-email:sync-gmail` | **2%** | 968 | ~968 ms/min |
| 10 | `service-cases:process-automation-pending` | **1.5%** | 619 | every minute |
| 11 | `GET /dashboard` (SSR) | **1.5%** | ~600 | 594 ms / 57 SQL / 321 KB |
| 12 | `presence:process-timeouts` | **1.2%** | 494 | every minute |
| 13 | `outbox:process` (cron) | **1.1%** | 468 | idle ~0.5s (webhooks also drain) |
| 14 | `ira:flush-assignment-telegram-batches` | **1.1%** | 481 | every minute |
| 15 | `GET /dashboard/team-activity` | **1.0%** | ~400 | **1039 ms / 185 SQL** when expanded |
| 16 | `GET /dashboard/activity` | **0.7%** | 288 | ~24 ms; ~12/min modeled |
| 17 | `radiumbox:recover-sync` (*/15m) | **0.5%** | ~200 | scanned **1316**, recovered **11** |
| 18 | Bonvoice webhook sync `process()` | **0.5%** | ~200 | still calls global outbox `process()` |
| 19 | `missing-serial:process` (*/15m) | **0.4%** | ~150 | ~596 ms |
| 20 | `POST /presence/heartbeat` | **0.1%** | ~46 | ~2.3 ms |

### Compact ranking (requested format)

```
platform:snapshots:warm ........ 28%
automation:snapshot ............ 16%
queue:work (RadiumBox jobs) .... 12%
/api/webhooks/interakt+process . 12%
watchdog:send-critical-alerts .. 10%
/dashboard/live ................  9%
schedule:run ...................  4%
/notifications/poll ............  3%
inbound-email:sync-gmail .......  2%
automation-pending ............. 1.5%
/dashboard SSR ................. 1.5%
presence:process-timeouts ...... 1.2%
outbox:process (cron) .......... 1.1%
ira:flush-telegram-batches ..... 1.1%
/dashboard/team-activity ....... 1.0%
/dashboard/activity ............ 0.7%
radiumbox:recover-sync ......... 0.5%
Bonvoice webhook process() ..... 0.5%
missing-serial:process ......... 0.4%
/presence/heartbeat ............ 0.1%
```

---

## HTTP request table (measured)

| URL | Count/min | Avg ms | P95 | Max | Peak MB | SQL | Notes |
|-----|----------:|-------:|----:|----:|--------:|----:|-------|
| `GET /dashboard/live` | 5–8* | 590 | ~1066 | 1378 | ~70 | 7 warm / 47–69 cold | Phase 1 OK; payload ~514 KB |
| `GET /dashboard` | 1–3* | 594 | 594 | 594 | ~70 | 57 | SSR |
| `GET /dashboard/team-activity` | 0–2* | 1040 | 1040 | 1040 | ~70 | 185 | expanded only |
| `GET /notifications/poll` | ~21* | 68–74 | ~80 | 80 | ~70 | 6 | 20s poll |
| `GET /dashboard/activity` | ~12* | 18–24 | 24 | 24 | ~70 | 11 | cheap |
| `GET /dashboard/realtime/connection-status` | low | 15 | 15 | 15 | ~70 | 0 | |
| `POST /presence/heartbeat` | ~4* | 2.3 | 2.3 | 2.3 | ~70 | 2 | |
| `POST /api/webhooks/interakt` | 3.4 (peak 16) | n/m HTTP | lag p50/avg ~23s | lag max 238s | n/m | enqueue + **global `process()`** | Phase 2 not deployed |

\*rpm modeled (access logs not exposed). Sessions=7; Ably heartbeat → live ~60s; notifications 20s.

Cold live top SQL (first sample): audit_logs exists/select ×15, cache ×5 — **not** `app.settings.all` stampede.

---

## Workers / cron / queues (measured)

### Process reality

- **lsphp** workers: bursts of 3–8 concurrent `lsphp:ins … index.php`, earlier samples **50–68% CPU** each, one worker alive **3–4+ minutes**.
- **Artisan**: no permanent queue daemon; cron spawns `queue:work` / warmers each minute.
- **Reverb**: not running (Ably used).
- **Supervisor**: not present as a long-lived manager for this app.

### Top-of-minute sample (07:02:00 UTC)

| Time | Signal |
|------|--------|
| :02 | `queue:work` **59%**, `schedule:run` **53%** |
| :04–:18 | `platform:snapshots:warm` **72% → 88%** sustained |
| lsphp | secondary in this window (0–23% aggregate) |

### Wall-clock command timings (same host)

| Command | Wall ms |
|---------|--------:|
| `platform:snapshots:warm` | **11613** |
| `watchdog:send-critical-alerts` | **21208** |
| `automation:snapshot` | **6607** |
| `queue:work` (capped 10s test) | 2667 |
| `radiumbox:recover-sync` | 1315 (scanned 1316, recovered 11) |
| `inbound-email:sync-gmail` | 968 |
| `outbox:process` (idle) | 468 |

Interakt synthetic (rolled back): enqueue **14.8 ms**; `process()` **12.5 ms** / 9 queries when outbox quiet — **does not represent contended production** (lags 216–238s observed).

---

## Patterns investigated

| Pattern | Finding |
|---------|---------|
| Polling storm | Notifications 20s still; live in Ably heartbeat. Not #1 after Phase 1. |
| Webhook storm | Interakt ~3.4/min, peaks 14–16/min; **still sync global outbox drain** |
| Retry / recover loops | `radiumbox:recover-sync` scans **1316** / 15m, recovers 11 — recurring work |
| Queue starvation | `jobs` pending 0; enrichment completes; CPU spent in warmers + sync webhooks |
| Lock waits | MySQL `SHOW PROCESSLIST` mostly Sleep; `innodb_trx` denied (no PROCESS privilege). Outbox had **4 Cashfree rows stuck in `processing`**. |
| Deadlocks | Not observable with available privileges |
| Infinite loops | Not proven; **cron pile-up every minute** is the measured saturation mode |
| Duplicate browser tabs | 7 sessions; multi-tab still multiplies polls (secondary) |

---

## What changed vs prior inventory

1. **Phase 1 worked for SQL** on `/dashboard/live` (5000+ → ~7 warm).
2. **Live still costs PHP/Blade time** (~0.3–1.4s, ~514 KB) but is **#6**, not #1.
3. **Real #1 is scheduler**: `platform:snapshots:warm` + `automation:snapshot` + `queue:work` + watchdog.
4. **Phase 2 not in production** — Interakt remains a burst amplifier via sync `process()`.

---

## Highest-leverage next actions (measurement conclusion only)

1. Reduce frequency/cost of `platform:snapshots:warm` and `automation:snapshot`.
2. Deploy Phase 2 Interakt enqueue-only (and stop Bonvoice global `process()` similarly).
3. Profile/fix `watchdog:send-critical-alerts` (21s).
4. Clear/investigate Cashfree outbox rows stuck in `processing`.
5. Re-check `radiumbox:recover-sync` recovering the same set every 15 minutes.

---

## Method / limits

- SSH process samples, `artisan` wall clocks, Laravel `HttpKernel` probes as user id=1, DB counts for Interakt/outbox/jobs.
- **No Hostinger access logs** — HTTP rpm modeled from sessions + known poll intervals.
- Host load average is **node-wide** (shared hosting); percentages above are **relative account work**, not % of entire machine.
- No code or config was changed during this investigation.
