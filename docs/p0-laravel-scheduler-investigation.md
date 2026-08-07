# P0 — Laravel Scheduler (`schedule:run`) Investigation

**Status:** Investigation complete → **Phase 10 implemented** (light-tick + cadence retune)  
**Prompt:** P[07-08]-025  
**When:** 2026-08-07 · production probe ~14:42 IST  
**Host:** `desk.radiumbox.com` via `tools/config.sh` (SSH port 65002)  
**Deploy HEAD (investigation):** `bbf2de9b` `perf(assign): coalesce batch assign side effects and driver guide dispatch`  
**Canvas:** [`p0-laravel-scheduler-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-laravel-scheduler-investigation.canvas.tsx)

Related: [p0-production-cpu-request-inventory.md § Phase 10](./p0-production-cpu-request-inventory.md#phase-10--scheduler-light-tick--cadence-retune-implemented) · [p0-production-remeasure-after-optimizations.md](./p0-production-remeasure-after-optimizations.md) · [radium-desk-performance-audit.md §6](./radium-desk-performance-audit.md#6-background-jobs) · [hostinger-scheduler-cron-wrapper.md](./hostinger-scheduler-cron-wrapper.md)

---

## Verdict

**Yes — `schedule:run` is still a production bottleneck**, but the cost is **what it runs every minute under Hostinger flock**, not Laravel’s scheduler framework.

| Question | Answer |
|----------|--------|
| Is parent orchestration expensive? | **No** — heartbeat ~5 ms; parent mostly waits on children |
| Is the cron tick expensive? | **Yes** — natural tick **12.3 s** wall; holds flock that long |
| Dominant child? | **`platform:snapshots:warm` ~9 s** (~73% of tick) |
| Hidden tax? | **~2.5 s/min artisan process spawn** — each noop command ≈ **500 ms boot** |
| Mutex defect? | **`automation:snapshot` light tick stuck** on legacy 24h lock until **2026-08-08 11:26 IST** |
| Queue inside schedule? | **Correctly gated off** — `QUEUE_WORKER_MODE=dedicated_cron` |

No implementation in this pass. Highest-leverage follow-ups are cadence cuts + spawn consolidation + clearing the stuck mutex (ops).

---

## Production facts

| Fact | Value |
|------|------:|
| Load 1/5/15 | 20.0 / 18.9 / 18.9 |
| `CACHE_STORE` | `database` |
| `QUEUE_WORKER_MODE` | `dedicated_cron` (`runsViaScheduler=no`) |
| Cron #1 entrypoint | `bin/schedule-run.sh` (expected) |
| Artisan boot (`about` / `list`) | **630 ms / 463 ms** |
| Natural `schedule:run -v` wall | **12 332 ms** |
| Active `cache_locks` schedule rows | 2 (gmail short + **stuck automation light**) |

---

## Minute timeline (measured)

Natural `php artisan schedule:run -v` at **2026-08-07 14:42:18 IST**:

| Minute phase | Commands started | Commands skipped | Commands completed | Wall |
|--------------|------------------|------------------|--------------------|-----:|
| :00.0 | heartbeat | `queue:work` (when gate) | heartbeat | 5 ms |
| :00.0–:01.1 | automation-pending, ira flush | `automation:snapshot` (**Has Mutex**) | both (0 business work) | 1.06 s |
| :01.1–:10.1 | `platform:snapshots:warm` | — | warm (partial zones) | **9.0 s** |
| :10.1–:10.5 | `outbox:process` | — | outbox | 0.45 s |
| :10.5 | `inbound-email:sync-gmail` (background) | — | parent spawn only | 1 ms parent |
| :10.5–:12.3 | presence, appointment-reminders | — | both (0 work) | 0.95 s |
| :12.3 | — | — | **parent exit / flock release** | **12.3 s total** |

**CPU during tick:** warm dominates process CPU (~70% class from prior remasures). Noop children are mostly boot + light SQL. Background Gmail continues after parent exit (~47% CPU sampled on child).

---

## Every scheduled command (inventory)

Source: `bootstrap/app.php` + production `schedule:list` + Event property probe.

| Command | Freq | Gate | Overlap TTL | BG | Idle behavior | Measured wall | Rec. freq |
|---------|------|------|-------------|----|---------------|--------------:|-----------|
| `operations:scheduler-heartbeat` | 1m | — | none | N | Cache/file put | 5 ms | **Keep 1m** |
| `queue:work … --max-time=55` | 1m | `runsViaScheduler()` | 1440 | N | **Skipped in prod** | — | Keep dedicated_cron |
| `service-cases:process-automation-pending` | 1m | grace enabled | 1440 | N | Usually 0 | 602 ms | Keep 1m / **2m OK** |
| `infrastructure:metrics:collect` | 5m | metrics on | 1440 | N | Light collect | 621 ms | Keep 5m |
| `service-cases:process-deferred-smart-assignment` | 5m | smart+deferred | 1440 | N | Empty pending | n/m | Keep 5m |
| `ira:flush-assignment-telegram-batches` | 1m | batch on | 1440 | N | 0 batches (tail) | 457 ms | **2m OK** |
| `automation:snapshot` | 1m | — | **5** | **Y** | **Skipped (mutex)** | 5350 ms manual | Keep 1m; **clear lock** |
| `automation:snapshot --reconcile` | 15m | — | **20** | **Y** | Full rebuild | 5–7 s log | Keep 15m |
| `executive:snapshot` | hourly | — | 1440 | N | Always captures | n/m | Keep hourly |
| `platform:snapshots:warm` | 1m | — | 1440 | N | Partial warm typical | **9783 ms** | **2–5m (prefer 5)** |
| `outbox:process` | 1m | — | 1440 | N | 0–few events | 451–789 ms | Keep 1m; **`--limit=50`** |
| `inbound-email:sync-gmail` | 1m | inbound+gmail | **10** | **Y** | Often pulled 0 | bg | **2–5m** |
| `presence:process-timeouts` | 1m | — | 1440 | N | Usually 0 away | 462–509 ms | **2m OK** |
| Attendance / IRA daily / PI snapshot | daily | various | 1440 | N | Once/day | — | Keep |
| `ira:send-risk-alerts` | hourly | — | 1440 | N | Send if risks | — | Keep / 2h |
| `watchdog:send-critical-alerts` | 5m | watchdog on | 1440 | N | 0 alerts common | **4813 ms** | Keep 5m |
| `team-telegram:send-daily-briefings` | 15m | telegram on | 1440 | N | Quiet rules | — | Keep 15m |
| `team-telegram:send-slot-reminders` | hourly | telegram on | 1440 | N | Quiet rules | — | Keep hourly |
| `team-telegram:send-appointment-reminders` | 1m | telegram+reminders | 1440 | N | **98% zero window** | 488–505 ms | **5m** |
| `automation:run` | hourly | setting | 1440 | N | Often disabled | — | Keep |
| `radiumbox:recover-sync` | 15m | recovery on | 1440 | N | Scan; few recoveries | — | Keep 15m |
| `missing-serial:process` | 15m | enabled | 1440 | N | Often 0 | — | Keep 15m |
| `cashfree:auto-recover-missing` | 5m | auto_recover on | 1440 | N | Reconcile even if 0 | **5039 ms** | **10–15m** |

---

## Commands that wake every minute but usually do nothing

| Command | Evidence | Still pays |
|---------|----------|------------|
| `ira:flush-assignment-telegram-batches` | Last 60 log lines: **0** non-zero flushes | ~450–530 ms (boot) |
| `presence:process-timeouts` | Mostly “Processed 0”; 4/60 non-zero in sample | ~460–510 ms |
| `service-cases:process-automation-pending` | Usually 0 processed / 0 pickup | ~600 ms |
| `team-telegram:send-appointment-reminders` | **30697 / 31222** matched window = 0 | ~500 ms + diagnostic COUNTs |
| `outbox:process` | Often 0; sometimes 1–3 | ~450–800 ms |
| `platform:snapshots:warm` | Rare all-skip (3 all-skip vs 106 any in log); usually warms 3–6 zones | **~9–10 s** |
| `automation:snapshot` light | **Not running** (mutex) | Would pay fork + cache when unlocked |
| `inbound-email:sync-gmail` | ~107/200 recent lines `pulled 0` | Background PHP + Gmail HTTP |

---

## Overlap / mutex waits

| Finding | Detail |
|---------|--------|
| Stuck lock | `framework/schedule-2015da66…` = **automation:snapshot light**; expires **2026-08-08 11:26 IST** |
| Code vs lock | Event now `withoutOverlapping(5)` + `runInBackground`; **old 24h lock row still wins** |
| Reconcile path | Still runs every 15m (different mutex); logs show `Mode: reconcile` 5–7 s |
| Overlap pile-up | Not concurrent overlap of warm — warm is foreground sequential; risk is **skip-until-TTL** after crash |
| Default TTL debt | Nearly all other events still `expiresAt=1440`; `config/scheduler.php` overlap map **not wired** |
| Hardening tests | `SchedulerHardeningTest` expects bg + 2m TTL + `--limit` on several jobs — **out of sync with bootstrap** |

---

## Process creation

| Style | Count / quiet minute | Cost |
|-------|---------------------:|------|
| In-process closure | 1 (heartbeat) | ~5 ms |
| Foreground `php artisan` children (`appendOutputTo`) | **6** | ~500 ms boot each |
| Background `runInBackground` | 1 (gmail); +1 when automation unlocked | Extra concurrent PHP |
| Dedicated Cron #2 `queue:work` | Separate from `schedule:run` | Not in parent wall |

**Key insight:** measured “noop” walls (~450–600 ms) ≈ **artisan bootstrap**, not business SQL. Consolidating light every-minute work into **one** dispatcher would remove ~2.5 s/min from flock-held wall without changing product cadence.

---

## Top scheduler waste (rank)

```
1. platform:snapshots:warm @1m foreground .......... ~9s/min
2. Artisan process spawn tax (6 boots) ............. ~2.5s/min
3. Stuck automation:snapshot mutex ................. light tick dead
4. appointment-reminders @1m (98% idle) ............ ~0.5s × 60/h
5. gmail sync @1m background (often empty) ......... extra PHP+HTTP
6. withoutOverlapping 1440m on most events ......... stuck-skip risk
7. Hardening intent not applied .................... limits/TTL/bg debt
8. ira flush / presence / automation-pending @1m ... boot-only wakeups
```

---

## Recommended schedule matrix (summary)

| Keep at 1m | Move to 2m | Move to 5m | Move to 10–15m | Structural |
|------------|------------|------------|----------------|------------|
| heartbeat | presence | **platform warm** | cashfree auto-recover | **One light-tick dispatcher** (cut boots) |
| outbox (+`--limit=50`) | ira telegram flush | appointment reminders | (or keep cashfree 5m if recovery SLA needs it) | Wire `config/scheduler.php` overlap TTLs |
| automation light (after mutex clear) | automation-pending (optional) | gmail sync (2–5m) | reconcile stays 15m | Never put `queue:work` back in schedule |

---

## Ops note (read-only finding)

Clearing `cache_locks` key `framework/schedule-2015da66de0eba9a3411f51e4466b6c1180ea591` (or `php artisan schedule:clear-cache`) will restore every-minute automation light ticks. That is **correctness/freshness**, not a free CPU win — expect incremental/background automation CPU to return.

---

## Method / limits

- SSH: `schedule:list`, Event mutex/`expiresAt` probe, natural `schedule:run -v`, per-command wall clocks, log tails, `cache_locks`, `QueueWorkerMode`.
- Hostinger hPanel crons are not visible via `crontab -l`; Cron #2 inferred from live `queue-worker.log`.
- Manual `schedule:run` can race the real cron; timeline above is one clean verbose pass.
- No code or config changed.

---

## Phase 10 follow-up (implemented)

Cadence + consolidation from the recommended matrix shipped — see [inventory Phase 10](./p0-production-cpu-request-inventory.md#phase-10--scheduler-light-tick--cadence-retune-implemented).

| Recommendation | Phase 10 |
|----------------|----------|
| Consolidate light 1m commands | `schedule:light-tick` |
| Warm → 5m | yes |
| Appointment reminders → 5m | yes |
| Gmail → 2–5m | **2m** |
| Cashfree recover → 10–15m | **15m** |
| Keep heartbeat / outbox / automation | yes (outbox via light-tick + `--limit`) |
| Wire short overlap TTLs | yes on retuned events |

## Updates to existing performance docs

- [radium-desk-performance-audit.md §6](./radium-desk-performance-audit.md#6-background-jobs) — Phase 10 before/after  
- [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) — Phase 10 section + benchmarks + rollback  
