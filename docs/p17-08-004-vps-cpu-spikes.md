# P17-08-004 — VPS CPU spikes with few desk users

Canvas: [`/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p17-08-004-vps-cpu-spikes.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p17-08-004-vps-cpu-spikes.canvas.tsx)

**Inspection:** 2026-08-17 04:05–04:23 UTC / 09:35–09:53 IST  
**Mode:** Read-only. Nothing killed, restarted, deployed, or changed.

## Verdict

The AIC 50–100% spikes are **real on this container**, not only a load-average illusion. They are a **combination of B + A + C**, with **E** explaining the misleading `load ~12` number.

| Code | Meaning | This VPS |
|---|---|---|
| A | Application / HTTP | **Yes** — 6 staff active; one `lsphp` worker used **63% of a core** in a 10s sample |
| B | Scheduled / background | **Yes — primary spike generator** — every-minute artisan pack + 9s `platform:snapshots:warm` + Gmail every 2 min |
| C | Database | **Yes, secondary** — MariaDB **~19% of a core** in the same 10s window, idle between ticks |
| D | External / bot | **Present but cheap** — HetrixTools is **129/135** recent sessions; tunnel ~1–2 rps |
| E | I/O / loadavg artifact | **Yes for `uptime` load** — this is **LXC**; `/proc/loadavg` is the **host** (6600+ tasks). Do not use load 12 as this VM’s CPU |

**Recommendation:** 2 vCPU is actually tight for the current mix. Resize to 4 vCPU is justified to absorb the overlapping spikes. Also fix the workload: the every-minute scheduler pack and 9s snapshot warm will still spike a 4 vCPU box, just less dangerously.

Do not treat “no humans logged in” as the premise. At 09:52 IST six staff had `last_active_at` within 10 minutes.

---

## 1. Load and nproc

| Metric | Value | Whose? |
|---|---|---|
| `nproc` | **2** | This container (`cpu.max = max 100000`, `VIRT=lxc`) |
| `/proc/loadavg` | **8.96–13.34** (1-min) during the window | **Host** — `4/6648` … `11/6694` tasks; this guest has ~100 processes |
| Container CPU pressure | `some avg10` **4–20%** | This cgroup |
| Host vmstat | us 10–31%, sy 11–18%, id 34–68%, wa 2–16%, **gu (guest) 3–12%**, st 0 | Host node |

`ps` `%CPU` on long-lived processes is **0.0** because it is lifetime average. Instantaneous cost was measured from **cgroup `usage_usec`** and `/proc/*/stat` jiffies.

---

## 2. Five-minute cgroup CPU (this container only)

Percent of **2 vCPU** from `usage_usec` deltas (not host loadavg):

| Interval (UTC) | CPU-sec | % of 2 vCPU |
|---|---:|---:|
| 04:07:46–04:10:04 | 47.7 / 138 s | **17%** |
| 04:10:04–04:10:56 | 26.2 / 52 s | **25%** |
| 04:10:56–04:11:24 | 19.4 / 28 s | **35%** (`:11` = `platform:snapshots:warm`) |
| 04:11:24–04:11:56 | 24.8 / 32 s | **39%** |
| 04:11:56–04:12:15 | 17.0 / 19 s | **45%** |
| 04:12:15–04:12:35 | 4.1 / 20 s | **10%** |
| 04:12:35–04:12:52 | 6.0 / 17 s | **18%** |
| **04:23:04–04:23:14** | **17.8 / 10 s** | **89%** |

5-minute span 04:07:46–04:12:52: **145 CPU-sec / 306 s wall ≈ 24% of 2 vCPU average**, with repeated 35–45% bursts at scheduler alignment. A later 10s sample hit **89% of 2 vCPU** — that is the AIC 50–100% shape.

`pidstat` / `mpstat` are not installed. `top` lifetime `%CPU` hid the bursts; cgroup + jiffies did not.

---

## 3–5. Scheduler, Gmail, queue

Cron (`ravi`), every minute:

- `bin/schedule-run.sh` (real scheduler, not a freeze stub)
- `/home/ravi/queue-worker.sh` (`queue:work` `--stop-when-empty --max-time=55`)

No long-lived `queue:work`. `jobs=0`, `failed_jobs=2`.

Caught at **04:20:10 UTC** (09:50 IST tick):

- `inbound-email:sync-gmail` PID 189228 (parent 189227) — running, not stuck
- `missing-serial:process` PID 189231 — `R` state

Gmail cadence is every **2 minutes**. Morning pulls were **16–50 messages/run** (not idle zeros). Parent `schedule:run` only records spawn (~20 ms); the child keeps running afterward.

Every-minute parent wall (09:45–09:50 IST), sequential artisan boots:

| Command | Typical wall |
|---|---|
| heartbeat | 12–57 ms |
| `schedule:light-tick` | **0.9–2 s** |
| `reminders:dispatch-due` | **0.6–1 s** |
| `automation:snapshot` | background spawn |
| `platform:snapshots:warm` (every 5 min, `:01/:06/:11/…`) | **9 s** |
| plus Gmail / telegram / recover-sync / missing-serial / watchdog when due | 1–2 s parent, more in children |

Queue: occasional `RadiumBoxOrderEnrichmentJob` **0.4–1 s**, not a resident worker.

---

## 6. MariaDB

`mariadb` **active**, `read_only=0`, processlist **2** (Sleep + the inspect query) at 09:51 IST.

10s jiffy sample 04:23:04–04:23:14: MariaDB **1.92 CPU-sec ≈ 19% of 1 core ≈ 10% of 2 vCPU**. Lifetime average is low; it spikes when HTTP/scheduler query.

---

## 7. LiteSpeed / lsphp

5 `lsphp` workers. Two were bound to `public/index.php`.

Same 10s sample (HZ=100):

| PID | CPU-sec in 10s | Share of 1 core |
|---|---:|---:|
| 164247 | **6.33** | **63%** |
| 162612 | 1.27 | 13% |
| 162605 | 0.86 | 9% |
| 162615 | 0.33 | 3% |
| **lsphp sum** | **8.79** | **88% of 1 core / 44% of 2 vCPU** |

That is live HTTP, not cron PHP.

VPS LiteSpeed access logs were not readable as `ravi`. HTTP volume from tunnel metrics: **8913 → 8957** total requests (~44 in ~30 s ≈ **1.5 rps**). Concurrent proxied requests: **0** at idle samples. Errors: **0**. HA connections: **4**.

---

## 8. cloudflared

PID 118010, tunnel healthy, 4 HA connections. 10s sample **0.03 CPU-sec** (~0.3% of 1 core). Not a spike cause.

---

## 9. Cron / scheduled commands

See `bootstrap/app.php`. Business-hours overlap that matches the spikes:

- Every minute: heartbeat, light-tick, reminders, automation snapshot, queue worker
- Every 2 min: Gmail sync (pulling mail this morning)
- Every 5 min: `platform:snapshots:warm` (**9 s**), metrics, telegram appointment reminders, watchdog
- Every 15 min: recover-sync, missing-serial, cashfree auto-recover

---

## 10. Access: humans vs bots

Sessions with `last_activity` in the last 10 minutes (09:52 IST):

| Class | Count |
|---|---:|
| HetrixTools uptime bot | **129** |
| Logged-in Chrome sessions | **6** |
| Distinct IPs | 28 |
| `users.last_active_at` < 10 min | **6** (Ravi, Avinash Jha, Shubhanshi Rathore, Sushant Shetty, Jyotsana Baranwal, Kanchan) |

Hetrix dominates **session rows** (likely hitting a session-starting URL, not a session-free `/up`). Six humans are enough for dashboard polling to light up `lsphp`. Tunnel rps is modest; the CPU cost is Laravel work per request, not a scrape flood.

---

## 11–12. Top 3 causes (quantified)

Attribution of the **04:23:04–04:23:14** 10s window (container used **17.8 CPU-sec = 89% of 2 vCPU**):

| Rank | Cause | CPU-sec | ≈ % of 2 vCPU in that window |
|---|---|---:|---:|
| **1** | LiteSpeed `lsphp` HTTP (staff desk + whatever Hetrix hits) | 8.8 | **44%** |
| **2** | Remainder (scheduler artisan children not in the long-lived PID set: light-tick / reminders / snapshot / Gmail / watchdog) | ~7.1 | **~35%** |
| **3** | MariaDB | 1.9 | **10%** |
| — | cloudflared | 0.03 | **~0%** |

Across the earlier 5-minute window, **scheduler alignment** is what *repeats* the spikes (`:11` warm → 35–45% of 2 vCPU). HTTP is what *pushes a spike toward 90–100%* when staff are on desk.

---

## Pattern

**B + A + C**, with **E** for loadavg and **D** as high session count / low rps.

AIC is not lying about 50–100% **if it graphs this container**. It *would* be lying if it graphs the LXC **node** (load 12, 6600 tasks, guest CPU 3–12% on the host). The cgroup series shows this guest alone can hit **89% of 2 vCPU** for 10s, which is enough to explain the dashboard without blaming the neighbors — neighbors still make `uptime` look worse than this VM is.

---

## Recommendation

1. **4 vCPU / 8 GB is justified** for production: 2 vCPU cannot absorb staff HTTP + 9s snapshot warm + Gmail ingest + MariaDB on the same box without 50–100% spikes.
2. **Still fix workload**, or 4 vCPU will just spike to 40% instead of 90%:
   - `platform:snapshots:warm` 9s every 5 minutes is the largest single scheduled wall time
   - Gmail every 2 minutes pulling 16–50 messages during the morning
   - Every-minute artisan process spawns (light-tick + reminders + snapshot + queue worker)
   - Point Hetrix at `/up` only and keep it off session middleware if it is not already
3. **Do not use `load average` on this LXC** as the upgrade trigger. Use cgroup `usage_usec` or AIC **container** CPU, not node CPU.

Stopped here. No processes were signaled and the VPS was not upgraded.
