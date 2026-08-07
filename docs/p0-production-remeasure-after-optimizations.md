# P0 Production Remeasure — After Optimizations

**Status:** Read-only production measurement (no code changes)  
**When:** 2026-08-07 · **07:45–08:20 UTC** (35 minutes, 137 samples @ 15s)  
**Host:** `desk.radiumbox.com` via `tools/config.sh` (SSH port 65002)  
**Deploy:** `a8faa1e0` `perf(core): reduce production CPU across snapshots, automation, polling and queues`  
**Baseline:** [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md) (06:57–07:02 UTC, HEAD `30daa64`, Phase 1 only)  
**Canvas:** [`p0-production-remeasure-after-optimizations.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-production-remeasure-after-optimizations.canvas.tsx)

---

## Verdict

Phases 1–7 **are deployed** and several hot paths are clearly cheaper (Interakt HTTP enqueue-only, warm skip-when-fresh, automation SQL, watchdog wall, RadiumBox enrichment ms).  

**Host load and account CPU have not collapsed.** Over 35 minutes, load1 averaged **22.8** (baseline window 22–27). Cron still spends ~**10s/min** in `platform:snapshots:warm` at ~70% process CPU when zones expire. `automation:snapshot` is **not running on the scheduler** because of a stuck `withoutOverlapping` mutex since ~05:55 UTC — that artificially removes ~16% of the old model but is an operational defect, not a win.

Fair estimated reduction of the prior TOP-consumer cost model: **~7%** if automation resumes full-rebuild every minute; **~30–35%** only while the stuck mutex keeps automation off. Biggest remaining consumers: warm, automation (when unlocked), `queue:work` (now dominated by `SendServiceReferenceDriverGuideJob`), live Blade payload, watchdog.

---

## Method

| Probe | Detail |
|-------|--------|
| Process / load sampler | Local → SSH every 15s for 35m (`tmp/p0-remeasure-20260807/samples.tsv`) |
| HTTP | Laravel `HttpKernel` as user id=1 (5× live, 5× notifications, 3× activity) |
| Cron wall | Natural `schedule:run` log + manual `artisan` wall clocks + process etime |
| Interakt | Code review (enqueue-only) + `writeProcessingJob` micro-bench + DB lag distribution |
| Errors | `laravel.log` scans for SQLSTATE 2002 / CAPTCHA / overload |

Limits: Hostinger access logs not exposed; `/proc/lve/fail` empty (CPU-limit faults **not readable** from SSH); host load is node-wide shared hosting.

---

## Before vs After (headline)

| Metric | Before (baseline remeasure) | After (this window) | Delta |
|--------|----------------------------:|--------------------:|------|
| Git HEAD | `30daa64` (Phase 1) | `a8faa1e0` (Phases 1–7) | All phases live |
| Load 1 / 5 / 15 | 22–27 | **avg 22.8 / 23.4 / 23.5** (p95 load1 29.6) | ≈ flat |
| Account Σ%CPU (artisan+lsphp) | bursts 50–88% on warm/queue | **avg 212 / p50 178 / max 677** (sum across procs) | still saturated |
| Hostinger CPU-limit faults | n/a | **Not observable** (`/proc/lve/fail` empty) | — |
| Active sessions (users 15m) | 7 | **7** | same |
| Jobs pending | 0 | **1–4** | no backlog |
| SQLSTATE 2002 | none noted | **0** (full log) | OK |
| CAPTCHA | none | **0** | OK |
| 403 / overload | none | **0 real** (broad grep false positives only) | OK |
| Scheduler overlap | mutexes present | **Stuck mutex** on `automation:snapshot` since ~05:55 | **Fail** |
| Queue backlog | none | none material | OK |

### Estimated total CPU reduction

| Scenario | Modeled TOP-consumer cost units | vs baseline (~41117) |
|----------|--------------------------------:|---------------------:|
| Current (automation cron blocked by mutex) | ~27200 | **≈ −34%** |
| Fair (mutex cleared, automation full-rebuild ~11s/min) | ~38200 | **≈ −7%** |

Cost units ≈ wall-ms amortized per minute (same method as baseline TOP20). Host load did not move with the optimistic scenario — treat **~7% fair** as the honest post-deploy system reduction until warm/automation walls drop further.

---

## Collected metrics

### `/dashboard/live`

| | Before | After |
|--|-------:|------:|
| Avg ms | 590 | **599** |
| P95 ms | ~1066 | **~499** (sample p95; max 1251 cold) |
| SQL | 7 warm / 47–69 cold | **7 warm / 47 cold** |
| Payload | ~514 KB | **~513 KB** |
| Peak MB | ~70 | **~78** |

Phase 1 still holding. No further live CPU win from Phases 2–7.

### Interakt webhook

| | Before | After |
|--|-------:|------:|
| Controller | enqueue + sync `process()` | **enqueue-only** (`writeProcessingJob`) |
| HTTP / enqueue avg | n/m (sync drain) | **~4.3 ms** (p95 ~1.7 ms after warm; 3 SQL) |
| Rate | ~3.4/min (peak 16) | **~6.3/min** (378/h; peak 26/min) |
| Queue lag avg / p95 / max | ~23s / — / 238s | **17.5s / 54s / 199s** (`received_at→processed_at`) |

HTTP path fixed. Outbox drain lag remains under cron/`outbox:process` contention (not the webhook request).

### `platform:snapshots:warm`

| | Before | After |
|--|-------:|------:|
| Wall (typical cron) | **11613 ms** (all 10 every minute) | **~10s** schedule; skip-all **15 ms**; partial 4-zone manual **16093 ms** under load |
| CPU while running | 72–88% | **avg ~69%** when sampled (n=21) |
| Behavior | always warm 10 | **avg ~5 warmed / ~5 skipped** (Phase 7) |

Skip-when-fresh works; remaining warmers (often `integration_health`, `critical_alerts`, `communications`) still cost ~10s wall.

### `automation:snapshot`

| | Before | After |
|--|-------:|------:|
| Wall | **6450–6607 ms** | Manual full-rebuild **10813–15405 ms**; SQL **193** (was **28073**) |
| Mode | always full | Reports `full-rebuild` (fingerprint rarely stable under live traffic) |
| Scheduler | every minute | **Blocked** — `schedule:list` shows **Has Mutex**; log mtime **05:55 UTC** |

### `watchdog:send-critical-alerts`

| | Before | After |
|--|-------:|------:|
| Wall | **21208 ms** | **10581 ms** (~50%) |
| Cadence | */5m | */5m (observed 08:20 UTC) |

### `queue:work`

| | Before | After |
|--|-------:|------:|
| Top-of-minute CPU | **59%** | **38–56%** typical when present (max sampled 66.5%) |
| RadiumBox enrichment | heavy / multi-second | **~20–84 ms** DONE |
| New hotspot | — | **`SendServiceReferenceDriverGuideJob` ~5–8s** (76 of last 200 DONE lines) |

### Notifications + req/min

| | Before | After |
|--|-------:|------:|
| `/notifications/poll` avg / SQL | 68–74 ms / 6 | **74.6 ms / 6** |
| Modeled notif rpm | ~21 (20s × 7) | **~8–9** if Phase 4 45s + Ably safety-net (code deployed; Ably `polling_active=false`) |
| Modeled total periodic rpm | ~48–55 | **~26–32** visible (Phase 4), live still ~6/min heartbeat |

---

## Remaining TOP 10 CPU consumers

Estimated % of account work (fair model: automation mutex cleared → full rebuild). Cost units ≈ ms/min.

| # | Consumer | Est. % | Cost units | Evidence |
|--:|----------|-------:|-----------:|----------|
| 1 | `automation:snapshot` (when mutex clears) | **24%** | 11000 | Full-rebuild 11–15s; 193 SQL; fingerprint misses under live traffic |
| 2 | `platform:snapshots:warm` | **22%** | 10000 | ~10s/min @ ~70% CPU; ~5 zones still rebuild |
| 3 | `queue:work` (DriverGuide + misc) | **11%** | 5000 | 38–56% CPU; DriverGuide 5–8s jobs |
| 4 | `GET /dashboard/live` | **8%** | 3594 | ~600 ms × ~6/min; 513 KB Blade |
| 5 | `SendServiceReferenceDriverGuideJob` (queue share called out) | **7%** | 3000 | Dominates recent queue DONE lines |
| 6 | `watchdog:send-critical-alerts` (amortized) | **5%** | 2116 | ~10.6s / 5 min |
| 7 | `schedule:run` orchestration | **4%** | 2000 | Stays up while children run |
| 8 | `inbound-email:sync-gmail` | **2%** | 900 | Background every minute |
| 9 | `GET /notifications/poll` | **1%** | 597 | ~75 ms × ~8/min |
| 10 | `outbox:process` + Interakt drain lag | **1%** | ~500 | ~0.5s cron; lag p95 54s |

Compact:

```
automation:snapshot (if unlocked) .. 24%
platform:snapshots:warm ............ 22%
queue:work ......................... 11%
/dashboard/live ....................  8%
SendServiceReferenceDriverGuideJob .  7%
watchdog (amortized) ...............  5%
schedule:run .......................  4%
inbound-email:sync-gmail ...........  2%
/notifications/poll ................  1%
outbox:process .....................  1%
```

---

## Verification checklist

| Check | Result |
|-------|--------|
| No SQLSTATE 2002 | **Pass** — 0 in `laravel.log` |
| No CAPTCHA events | **Pass** — 0 in last 20MB |
| No 403 due to overload | **Pass** — no LVE/503/Too Many Requests evidence |
| No scheduler overlap | **Fail** — `automation:snapshot` stuck **Has Mutex** (~21.6h remaining on 24h lock); not overlapping, but **skipped every minute** |
| No queue backlog | **Pass** — jobs 1–4; failed_jobs 1h = 0 |

---

## What improved vs what did not

**Improved**

- Interakt webhook HTTP no longer sync-drains outbox (~ms, 3 SQL).
- Warm skip-when-fresh: ~50% of zones skipped on average; skip-all path 15 ms.
- Automation SQL storm gone on full rebuild (28073 → 193).
- Watchdog wall ~50% (21.2s → 10.6s).
- RadiumBox enrichment jobs millisecond-class.
- Phase 4 polling code deployed (modeled rpm down when tabs use new intervals).

**Did not improve enough**

- Host load still ~23.
- Warm wall still ~10s most minutes.
- Automation wall still ~11–15s when it runs; fingerprint incremental rarely hits in production traffic.
- Live still ~0.6s / 513 KB.
- Queue CPU still high — hotspot shifted to `SendServiceReferenceDriverGuideJob`.
- Interakt **processing lag** still tens of seconds (outbox path).

---

## Operational note (read-only finding)

Clearing the stuck schedule lock for `automation:snapshot` (`cache_locks` key `framework/schedule-2015da66…`, expires ~2026-08-08 11:26 IST) would restore the cron. That will **increase** account CPU until Phase 6 incremental fingerprints hit more often or rebuild cost drops further. Do not treat the current “automation silent” window as a performance success.

**Follow-up (Phase 8):** Event-driven dirty-slice infrastructure + every-minute light tick + 15-minute `--reconcile` replaces fingerprint-as-gate. See [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) Phase 8. Short overlap TTLs (5m / 20m) reduce 24h stuck-mutex risk.

---

## Related

- Baseline: [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md)
- Inventory / phases: [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md)
- Phase 7 warm: [p0-platform-snapshots-warm-optimization.md](./p0-platform-snapshots-warm-optimization.md)
