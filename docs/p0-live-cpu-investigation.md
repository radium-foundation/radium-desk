# P0 Live CPU Investigation

**Status:** Investigate only (no code or config changes)  
**When:** 2026-08-07 · **11:42:31–11:45:34 UTC** (36 samples @ 5s) + follow-up probes to 11:47 UTC  
**Host:** `desk.radiumbox.com` via `tools/config.sh` (SSH port 65002)  
**Deploy:** `e1370d76` `feat(workflow): protect manual ownership and optimize scheduler`  
**Canvas:** [`p0-live-cpu-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-live-cpu-investigation.canvas.tsx)

Related: [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md) · [p0-production-remeasure-after-optimizations.md](./p0-production-remeasure-after-optimizations.md) · [p0-assign-reference-cpu-investigation.md](./p0-assign-reference-cpu-investigation.md)

---

## Verdict

This spike was **not** an endless Cashfree recovery loop and **not** primarily `platform:snapshots:warm`.

Sustained artisan CPU came from **`SendServiceReferenceDriverGuideBatchJob`** — **85 orders**, wall **4m 28s**, process CPU **~40–58%**, holding the single dedicated-cron `queue:work` well past `--max-time=55` (job `$timeout = 900`). Concurrent **long-lived `lsphp`** workers on `desk.radiumbox.com` dominated total account CPU.

Cashfree auto-recover had already cleared its recoverable backlog (**Found: 0** after recovering **33** earlier) and was **not** executing during the 3-minute profile.

---

## Profile summary

| Metric | Value |
|--------|------:|
| Load1 avg / max | 17.5 / 19.5 |
| Account Σ%CPU avg / p50 / max | **188 / 187 / 419** |
| lsphp Σ%CPU avg | **140** |
| artisan Σ%CPU avg | **48** |
| Auth users (15m) | **7** distinct |
| Guest sessions (15m) | 190 (**183 HetrixTools** bot) |

---

## Rank | Component | CPU | Wall | SQL | Root cause

| Rank | Component | CPU | Wall | SQL | Root cause |
|-----:|-----------|-----|------|-----|------------|
| 1 | `lsphp` HTTP (`desk.radiumbox.com`) | ≈52% | multi 30–150s workers | mixed; live warm 7 | Concurrent long PHP requests under load |
| 2 | `queue:work` → `SendServiceReferenceDriverGuideBatchJob` | ≈18% | **4m 28s** (85 orders) | per-order WA/email | Phase 9 batch; timeout 900; blocks worker |
| 3 | `lsphp` residual / short workers | ≈15% | seconds–minutes | n/m per URI | Same HTTP pool (URI hidden in `ps`) |
| 4 | `platform:snapshots:warm` | ≈2% window / high when run | ~10s @ ~72% | zone rebuild | */5m; recent ticks warmed **10/10** |
| 5 | `schedule:run` orchestration | ≈1.3% | ~7–28s parent | n/a | Holds while children run |
| 6 | `automation:snapshot --reconcile` | ≈1.1% | 5.5–7.0s | full rebuild | */15m; dirty health/validation/events/cashfree |
| 7 | `watchdog:send-critical-alerts` | ≈0.9% | ~5s @ 62% sampled | alert queries | */5m |
| 8 | `missing-serial:process` | ≈0.9% | ~7s sampled | scan 100 | */15m |
| 9 | `service-cases:process-deferred-smart-assignment` | ≈0.7% | ~1s | assignment | */5m boundary |
| 10 | `schedule:light-tick` | ≈0.5% | ~1s | outbox/presence | every minute |
| 11 | `GET /dashboard/live` (inside lsphp) | inside #1 | 319–1395 ms | 7 warm / 45 cold | Phase 1 OK; ~508 KB |
| 12 | `GET /dashboard` SSR | inside #1 | ~720 ms | 57 | Kernel probe |
| 13 | `GET /notifications/poll` | inside #1 | 67–93 ms | 6 | Secondary |
| 14 | `RadiumBoxOrderEnrichmentJob` backlog | after batch | 15–70 ms/job | enrichment | 21 jobs behind DriverGuide |
| 15 | `cashfree:auto-recover-missing` | **0% in profile** | idle Found:0 | n/a | Recovered 20+13 earlier; not in window |
| 16 | `automation:snapshot` (light) | 0% blocked | skipped | n/a | Stuck mutex ~18h remaining |
| 17 | outbox drain (light-tick) | low | seconds | outbox_events | pending≈0; failed 2783 historical |
| 18 | `inbound-email:sync-gmail` | low (bg) | bg */2m | gmail | Not top CPU |
| 19 | `radiumbox:recover-sync` | low | */15m | scan ~1350 | Earlier `order_record_id` SQL errors in log |
| 20 | HetrixTools uptime bot sessions | noise | n/a | session writes | Inflates guest session count only |

CPU% = share of measured account process CPU-sum proxy across 36 samples (not Hostinger panel %).

---

## Checklist

| # | Question | Finding |
|---|----------|---------|
| 1 | Top 20 by wall/CPU | Table above |
| 2 | Scheduler currently executing | `schedule:run` + dedicated-cron `queue:work` + :45 boundary warm/reconcile/watchdog/missing-serial; light-tick every minute |
| 3 | `cashfree:auto-recover-missing` running? | **No** during profile. Latest run Found:0 / Recovered:0. Earlier: Found 20→Recovered 20, Found 13→Recovered 13 |
| 4 | `platform:snapshots:warm` responsible? | **No** — secondary. Interval **5m**. Sampled ~72% for ~10s near :45 |
| 5 | `automation:snapshot` running? | Light **blocked** (mutex exp ~2026-08-08 11:26 IST). `--reconcile` **yes** (~5.5–7s). Dirty slices still all true |
| 6 | Queue workers / jobs | One `queue:work` on DriverGuideBatch **RUNNING → DONE 4m28s**. Then ~21 RadiumBox enrichment jobs |
| 7 | Top SQL during spike | `SHOW PROCESSLIST` mostly Sleep — CPU in PHP/outbound HTTP, not MySQL waits. Live warm queries sub-ms |
| 8 | Outbox backlog | pending **0**, processing **0–3**, failed **2783** (2742 `cashfree.webhook.deferred_operation` historical) |
| 9 | Cashfree recovery throughput | Burst recovered **33**; then Found:0. CF processed 64/15m, 205/1h. Residual failed **168**, received **8** |
| 10 | vs previous investigation | Dominant consumer **shifted** from warm-every-minute → **lsphp + DriverGuide batch**. Warm demoted by 5m interval. Automation light still mutex-stuck |

---

## Evidence highlights

### DriverGuide batch (smoking gun for artisan)

```
17:13:02  SendServiceReferenceDriverGuideBatchJob  RUNNING
17:17:31  SendServiceReferenceDriverGuideBatchJob  4m 28s DONE
```

- Job id 61259, queue `notifications`, **85** serialized orders, `$timeout = 900`
- Worker cmdline still `--max-time=55` — max-time is checked **between** jobs, so one batch can burn minutes

### Assign / communication volume (last 1h audits)

| Event | Count |
|-------|------:|
| `service_reference.assigned` | 83 |
| `whatsapp.template_sent` | 93 |
| `cashfree.order_tags_imported` | 121 |
| Orders created | 143 |

### HTTP Kernel probes (user id=1)

| Route | ms | SQL | Bytes |
|-------|---:|----:|------:|
| `/dashboard/live` | 1395 / 335 / 319 | 45 / 7 / 7 | ~508 KB |
| `/dashboard` | 720 | 57 | ~318 KB |
| `/notifications/poll` | 93 / 67 | 6 | ~6 KB |

---

## Compare with previous CPU investigation

| Metric | Prior (~07:00 UTC) | This live window (~11:42 UTC) |
|--------|--------------------|-------------------------------|
| Dominant CPU | `platform:snapshots:warm` every 1m (~28%) | **lsphp HTTP (~52%) + DriverGuide batch (~18%)** |
| Warm interval | every minute (~11.6s) | every **5m**; still 10/10 warm bursts |
| automation light | stuck mutex | **still stuck** (~18h left) |
| Auth users 15m | 7 | 7 |
| Queue smoking gun | RadiumBox enrichment | **DriverGuideBatch 85 / 4m28s** |
| Cashfree recover | outbox stuck processing | recovered 33 then **Found:0** |
| Account Σ%CPU | bursts 50–88% on warm | avg **188** / max **419** |

---

## Final answers

### Is this expected temporary recovery load?

**Partly.** Cashfree auto-recover already drained (Found:0 after 33 recovers). The live 100% window was dominated by an **85-order Assign Reference DriverGuide batch** plus concurrent lsphp — not an endless Cashfree retry loop.

### Is this a regression?

**Yes vs morning cron shape.** Phase 9 batch guide job can monopolize the single worker for minutes (`timeout` 900 vs worker `--max-time` 55). Warm full-zone clustering and the stuck automation mutex are continuing defects. Separate signal: `radiumbox` / `missing-serial` logs earlier showed `incidents.order_record_id` missing.

### Will it stop by itself?

**The DriverGuide spike already stopped** (DONE 4m28s). Cashfree recover is idle. Periodic warm/reconcile/watchdog continue. lsphp can stay elevated under normal traffic — expect relief from the multi-minute artisan spike, not a quiet host.

### Should any scheduler/command be stopped?

**No emergency stop recommended.**

- Do **not** kill `cashfree:auto-recover-missing` (idle; still needed for residual failed/received).
- Do **not** clear the automation mutex during a spike (would **add** CPU when light ticks resume).
- Queue worker already finished the batch; no kill needed now.

---

## Method / limits

- SSH process sampler 180s → `/tmp/cpu-profile-20260807T114231Z.log`
- Natural cron log mtimes + `queue-worker.log` DONE lines
- Laravel `HttpKernel` probes as user id=1; DB counts for outbox/jobs/Cashfree/sessions/audits
- No Hostinger access logs; lsphp request URI not visible via `/proc`
- Host load is node-wide shared hosting
- No code or config was changed
