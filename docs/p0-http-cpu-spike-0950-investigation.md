# P0 HTTP CPU Spike ~09:50 — Production Investigation

**Status:** Investigate only (no code, config, or optimizations)  
**Deploy:** `e1370d76`  
**When analyzed:** 2026-08-07  
**Canvas:** [`p0-http-cpu-spike-0950.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-http-cpu-spike-0950.canvas.tsx)

Related: [p0-lsphp-http-cpu-attribution-investigation.md](./p0-lsphp-http-cpu-attribution-investigation.md) · [p0-live-cpu-investigation.md](./p0-live-cpu-investigation.md)

---

## Executive summary

**LiteSpeed access logs are not available on this Hostinger account**, so a true access-log ranking of endpoints by request count / total wall / p95 for the spike window **cannot be produced**.

Using server clock (**UTC**), app timezone (**Asia/Kolkata**), audit logs, queue-worker logs, and prior Kernel probes:

1. The reported **09:50** jump best matches **09:50 UTC (15:20 IST)** — not IST morning (zero browser users then).
2. That window is a **mixed load**: multi-agent browser HTTP + Cashfree/Bonvoice automation HTTP + **Assign Reference / DriverGuide artisan** work.
3. **`GET /admin/operations/live` is still the only measured HTTP route** that runs 9–21s with hundreds–thousands of SQL — but **logs do not prove it was open or majority** during the spike.
4. **Confidence that ops/live alone caused the 09:50 panel spike: medium / unproven.** Confidence that it is the correct next HTTP optimization target: **high** (cost model).

---

## Evidence availability

| Source | Result |
|--------|--------|
| LiteSpeed / Hostinger access logs | **Missing** (no `domains/.../logs`, no `/usr/local/lsws/logs`, no `access.log` under account) |
| `lsphp` `/proc/.../environ` | No `REQUEST_URI` (prior profile) |
| `laravel.log` | Sparse; almost no per-request lines at 09:xx IST; not usable as access log |
| `sessions` table | **Not historical** (only current `last_activity`) |
| `audit_logs` | Usable for users/IPs/events/minute (not wall time) |
| `queue-worker.log` | IST timestamps; DriverGuide / enrichment walls |
| MySQL slow log | `slow_query_log=ON`, `long_query_time=3s`, file `in-mum2-web2219-slow.log` — **not readable** from account |
| Kernel probes (prior) | Wall / SQL / bytes for admin + dashboard routes |

---

## Timezone resolution

| Clock | Value |
|-------|-------|
| `date` on server | UTC |
| `APP_TIMEZONE` | Asia/Kolkata |
| Queue / Laravel log stamps | Asia/Kolkata |

| Candidate “09:50” | Finding |
|-------------------|---------|
| **09:50 UTC (15:20 IST)** | **Primary.** 9 users, 70 orders/30m, Assign Ref burst, browser audits continuous |
| 09:50 IST morning | **Rejected for HTTP.** 0 auth browser sessions; only scheduler radiumbox recovery. Queue did run ~250 single `DriverGuideJob` in 09:00–10:00 IST (artisan) |
| 15:50 IST | DriverGuideBatch **4m12s** — relevant if panel axis was misread |

---

## Timeline (primary window)

**UTC 09:45–10:15 ≡ IST 15:15–15:45 · 2026-08-07**

| UTC | IST | Event |
|-----|-----|-------|
| 09:30–09:45 | 15:00–15:15 | Pre: 784 human audits, 6 users, 40 orders, 37 WhatsApp sends |
| 15:08–15:09 IST | — | `SendServiceReferenceDriverGuideBatchJob` **1m 31s** |
| **09:50** | **15:20** | Reported CPU → 100%; human activity ongoing |
| ~15:17 IST | — | DriverGuideBatch **19s** |
| **09:53–09:54** | **15:23–15:24** | Assign Reference burst: **32 + 14** `service_reference.assigned` (user 2); human audits **121 / 86** per minute |
| 09:45–10:15 | 15:15–15:45 | 70 orders; 92 WA templates; 69 `ai_workbench.suggestion_viewed` |
| 10:15–10:45 | 15:45–16:15 | Still hot: 1612 human audits, 10 users, 97 orders |
| 15:50–15:54 IST | — | DriverGuideBatch **4m 12s** (later sustained artisan) |

Hourly UTC audits: hour 08 = 950 → hour 09 = **2979** → hour 10 = **3553**.  
Hourly UTC orders: 08 = 53 → 09 = **117** → 10 = **188**.

---

## Ranked hottest HTTP routes

### A. Natural access-log ranking (requested)

**Not available.** No access logs → cannot compute request count, total wall, avg/max wall, or total CPU per route for 09:45–10:15.

### B. Cost-model ranking (Kernel probes — production)

From the earlier production HttpKernel probes (user id=1), which remain the only per-route wall/SQL evidence:

| Rank | Route | Avg wall | Max wall | SQL | Est. frequency | Spike role |
|-----:|-------|----------|----------|-----|----------------|------------|
| 1 | `GET /admin/operations/live` | ~15.1s | **20.6s** | 550–5646 | ~1.3/min × open tabs @45s | Only multi-second HTTP path |
| 2 | `GET /admin/automation` | 5.5s | 5.5s | 226 | page loads | Occasional |
| 3 | `GET /admin/operations` | 1.5s | 1.5s | 373 | page loads | First paint |
| 4 | `GET /dashboard` / `/dashboard/live` | 0.29–1.7s | 1.7s | 7–93 | live ~30s × agents | Continuous; live SQL cheap |
| 5 | Webhooks / automation HTTP | n/m | n/m | n/m | order/call driven | ReactorNetty + python-requests as user 1 |
| 6+ | platform, IRA, email, finance, settings | &lt;100ms | &lt;100ms | low | nav | Not drivers |

**SQL totals in spike window:** not attributable per route without request instrumentation. Probe SQL for ops/live remains the outlier (thousands cold / hundreds warm).

---

## Request frequency / polling

| Endpoint family | Configured interval | Detected in spike? |
|-----------------|--------------------:|--------------------|
| `/admin/operations/live` | 45s (full refresh 180s) | **Not directly** (no access log); ops-capable users 1–4 active |
| `/dashboard/live` | 30s | Plausible — 7 browser users, 69 suggestion views |
| `/notifications/poll` | 45s | Plausible with Ably safety-net |
| C360 timeline | 45s | Only if drawers open |

**Overlap:** ops/live avg 15.1s &lt; 45s poll → single warm tab does not pile onto itself. Cold 20.6s still &lt; 45s. Overlap requires **multiple tabs/users** or other concurrent routes. True overlap evidence needs access logs.

**Saturation model (ops/live only):**

| Ops tabs | Avg concurrent 15s workers |
|---------:|---------------------------:|
| 1 | 0.34 |
| 2 | 0.67 |
| 3 | 1.01 |
| 4 | 1.34 |

Multiple agents on dashboard/live add more lsphp concurrency (each poll ~0.3s — light alone, material in aggregate with HTML size).

---

## Authenticated users / IPs (UTC 09:45–10:15)

### Browser (Mozilla)

| User | Audits | Role |
|------|-------:|------|
| #2 Avinash | 133 | Assign Reference lead (`157.119.126.199`) |
| #5 Jayram | 118 | Heavy case viewing |
| #10 Jyotsana | 17 | Case work |
| #7 Gaurav | 17 | Case work |
| #1 Ravi | 13 | Light browser |
| #3 Shipra | 8 | Case work |
| #6 Shubhanshi | 7 | Case work |
| #9 Abhinav | 5 | Case work |
| #4 Dileep | 1 | Ops-capable |

### Non-browser HTTP as user #1

| Client | Audits | IP | Events (top) |
|--------|-------:|----|--------------|
| ReactorNetty/1.3.6 | 434 | `52.66.101.190` (AWS Mumbai) | automation validation, cashfree tags, assign, missing_serial |
| python-requests | ~88+ | `103.163.64.190`, GCP | waiting_radiumbox, payment_received, missed_call_recovery |

`127.0.0.1` = **891** audits → queue/console, not browser lsphp.

Ops permission holders active in window: **1, 2, 3, 4**.

---

## Laravel logs

- No dense request trace for the window.
- Not a substitute for access logs.
- Errors/timeouts were not found as a dominant signal in the sampled ERROR grep for the afternoon hours (sparse log overall).

---

## MySQL slow queries

- `slow_query_log=ON`, `long_query_time=3`.
- File name `in-mum2-web2219-slow.log` — **not accessible** from the account filesystem search.
- Correlation to routes: **unavailable**. Prior Kernel probes already show ops/live N+1 (`incident_waiting_states`×~1890, `orders`×~1880, Cashfree integrity×216).

---

## Is `/admin/operations/live` responsible for the majority?

| Question | Answer | Confidence |
|----------|--------|------------|
| Was lsphp likely elevated? | Yes — stable memory, prior lsphp-dominant profiles, active browsers | Medium |
| Was ops/live the majority of HTTP CPU at 09:50? | **Unknown** — no access logs | — |
| Is ops/live the worst measured HTTP route? | **Yes** (9–21s, up to 5646 SQL) | High |
| Did artisan share the spike? | **Yes** — Assign Ref burst + DriverGuide batches | High |
| Could multiple tabs/users saturate workers? | Yes in model (3 ops tabs ≈ 1 full concurrent 15s worker + dashboard polls + webhooks) | Medium |

**Root cause (best supported):** Sustained afternoon operational load at **09:50 UTC** combining (1) multi-user Desk UI polling/navigation, (2) automation/webhook HTTP as user 1, and (3) Assign Reference → DriverGuide **queue** CPU — with **ops/live as the latent HTTP amplifier if any Operations tab was open**, but **not log-proven as majority**.

---

## Recommended optimization priority

1. **P0 — Fix `GET /admin/operations/live`** (eager-load waiting/order; cache Cashfree integrity; reduce poll rebuild). Highest measured HTTP ROI.  
2. **P0-observe — Enable access logging or request timing middleware** (path, user_id, wall_ms, sql_count) so the next spike can be attributed. Confirm Hostinger graph timezone.  
3. **P1 — Verify DriverGuide chunking (`DRIVERGUIDE_BATCH_SIZE=20`) on production** — multiple 1–4 minute batches same afternoon.  
4. **P2 — Shrink `/dashboard/live` HTML; measure ReactorNetty/Cashfree webhook path cost.**

**No implementation performed in this investigation.**

---

## Appendix — raw signals

```
BAND pre_0930_0945  human_audits=784  users=6  orders=40  wa=37  views=12  assigns=88
BAND spike_0945_1015 human_audits=1514 users=9 orders=70 wa=92 views=69 assigns=116
BAND post_1015_1045 human_audits=1612 users=10 orders=97 wa=59 views=49 assigns=170

Human audits/min peak: 09:53=121, 09:54=86 UTC
service_reference.assigned @09:53=32, @09:54=14 (user 2)

DriverGuideBatch (queue IST): 15:08 1m31s; 15:17 19s; 15:35 1m6s; 15:50 4m12s; …
Kernel ops/live: ms=[20597.9,9688.4] sql=[5646,550] kb~150.6
```
