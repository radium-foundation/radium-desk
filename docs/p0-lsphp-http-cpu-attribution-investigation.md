# P0 lsphp HTTP Worker CPU Attribution

**Status:** Investigate only (no code or config changes)  
**When:** 2026-08-07 · process profile **12:40:34–12:42:05 UTC** (18 samples @ 5s) + Kernel probes through ~12:45 UTC  
**Host:** `desk.radiumbox.com` via `tools/config.sh`  
**Deploy:** `e1370d76` `feat(workflow): protect manual ownership and optimize scheduler`  
**Canvas:** [`p0-lsphp-http-cpu-attribution.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-lsphp-http-cpu-attribution.canvas.tsx)

Related: [p0-live-cpu-investigation.md](./p0-live-cpu-investigation.md) · [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md)

---

## Executive summary

**Next P0 HTTP optimization: `GET /admin/operations/live`.**

Production Kernel probes (user id=1) measured:

| Metric | Cold / heavy | Warm / lighter |
|--------|-------------:|---------------:|
| Wall | **20.8s** | **9.1s** |
| SQL count | **5966** | **547** |
| SQL time | **7.1s** | **3.1s** |
| Response | **155 KB** HTML-in-JSON | same |
| Peak memory | **394 MB** | **126 MB** (earlier probe) |

Dominant N+1 on the heavy probe:

- `incident_waiting_states` × **1894**
- `orders` by id × **1886**
- Cashfree integrity: `exists(orders.cashfree_payment_id)` × **346**, plus `IN` lookups × **216** each on orders payment id / order id / `cashfree_webhook_logs` (webhook IN queries alone **3.3s**)

Live 90s process profile: **lsphp Σ%CPU avg 173 / max 628**; no `REQUEST_URI` in lsphp environ (0 hits / 18 samples). Auth users 15m: **7**. Poll config (runtime): live **30s**, notifications **45s**, operations **45s**, ops full refresh **180s**, C360 timeline **45s**, `BROADCAST=ably`, `CACHE=database`.

`/dashboard/live` remains #2 by frequency × payload (**~509 KB**, **7 SQL warm**, **~290 ms**) — SQL already fixed by Phase 1; PHP/Blade dominates.

---

## Method and limits

| Source | What it gives | Gap |
|--------|---------------|-----|
| `ps` sampler 90s | lsphp vs artisan CPU; long workers | **No URL** on Hostinger LiteSpeed |
| `/proc/<pid>/environ` | Would give `REQUEST_URI` if set | **Empty** for request keys |
| Access logs | Natural rpm + p95 | **Not exposed** on this host |
| `HttpKernel` probe as user 1 | Wall, SQL count/time, bytes, mem, duplicate SQL | Synthetic; can warm caches; not true multi-user mix |
| `PerformanceRuntimeConfig` | Poll intervals for rpm model | Assumes tabs open |

**PID → URL correlation was not possible.** Ranking uses measured per-route cost × modeled request rate.

Concurrent non-HTTP signal during the same 90s window: `php artisan automation:run` held **~74%** CPU continuously — separate from HTTP ranking, but explains elevated artisan Σ%CPU.

---

## Profile summary (90s)

| Metric | Value |
|--------|------:|
| Load1 avg / max | 15.1 / 16.5 |
| Account Σ%CPU avg / max | **247 / 702** |
| lsphp Σ%CPU avg / max | **173 / 628** |
| artisan Σ%CPU avg | **74** (dominated by `automation:run`) |
| Auth users (15m) | **7** |
| REQUEST_URI in lsphp environ | **0** |

Desk workers observed as `lsphp:…/desk.radiumbox.com/public_html/index.php` at 50–100% CPU with multi-second etimes during bursts.

---

## Ranked table

Cost model assumptions when an Operations tab is open:

- 1× `/admin/operations/live` every 45s  
- 7× `/dashboard/live` every 30s (upper bound if Ably falls back to poll)  
- 7× `/notifications/poll` every 45s  

CPU% = estimated share of **HTTP** work under that model (not Hostinger panel %).

| Rank | Route | CPU | Wall (measured) | SQL | Requests/min | Root cause | Recommended fix |
|-----:|-------|-----|-----------------|-----|--------------|------------|-----------------|
| 1 | `GET /admin/operations/live` | ~45–55% | 9.1–20.8s | 547–5966 | ~1.3 (1 tab @45s) | N+1 waiting/orders + Cashfree integrity + PHP/Blade | Eager-load; cache integrity; shrink rebuild |
| 2 | `GET /dashboard/live` | ~8–15% | 287–298ms warm | 7–9 | ~7–14 | Blade/PHP; 509 KB payload | Partial HTML / JSON |
| 3 | `GET /admin/automation` | ~5–10%† | 5.02–5.08s | 226 | page loads | Cashfree scans ×54; 234 KB | Reuse integrity read-model cache |
| 4 | `GET /dashboard` | ~2–5%† | 0.58–1.75s | 54–93 | nav | 46× dup query; 318 KB SSR | Dedupe query; warm snapshot |
| 5 | `GET /dashboard/team-activity` | ~2–4%† | 0.88–1.02s | 43–201 | expanded | Cold 201 SQL / ~500ms SQL | Cache payload |
| 6 | `GET /admin/operations` | ~1–3%† | 0.23–0.32s | 74–94 | page loads | First-paint only; DB cache×32 | Low vs `/live` |
| 7 | `GET /notifications/poll` | ~1–2% | 67–69ms | 6 | ~9.3 | Cheap; SQL-heavy relative to wall | Keep Ably safety-net |
| 8 | `GET …/customer-360` | ~1%† | 205ms | 65 | drawer | 65 KB; not continuous | Eager-load fragments |
| 9 | `GET /incidents/{id}` | ~1%† | 93ms | 41 | on open | Mild | Eager-load graph |
| 10 | `GET /admin/platform` | <1%† | 91ms | 105 | page loads | DB cache tax | Redis (infra) |
| 11 | `GET /admin/incoming-emails` | <1%† | 93ms | 52 | page loads | Not top | — |
| 12 | `GET /admin/system-settings` | <1%† | 89ms | 54 | rare | Not top | — |
| 13 | `GET /admin/ira-memory` | <1%† | 80ms | 30 | rare | Not top | — |
| 14 | `GET /settings` | <1%† | 73ms | 17 | rare | Not top | — |
| 15 | `GET /finance/dashboard` | <1%† | 70ms | 6 | page loads | Cheap | — |
| 16 | `GET /admin/administration` | <1%† | 69ms | 17 | rare | Cheap | — |
| 17 | `GET …/customer-360/timeline` | <1% | 28ms | 35 | @45s when open | Acceptable | Keep warm |
| 18 | `GET /dashboard/activity` | <1% | 13–16ms | 13 | when open | Cheap | — |
| 19 | `GET …/customer-360/device` | <1% | 9ms | 12 | when open | Cheap | — |
| 20 | `GET …/search-rows` | <1%† | 4ms | 4 | typed search | Cheap | — |

† Page-load / on-demand (share depends on navigation, not continuous poll).  
Wall ranges are measured min–max (n=1–3). True population p95 needs access-log sampling.

### Cost units (rpm × avg ms)

| Route | Est. cost units |
|-------|----------------:|
| `/admin/operations/live` | **~19,670** |
| `/dashboard/live` | ~4,060 |
| `/notifications/poll` | ~625 |
| Others | ≪ 500 each when not navigated |

---

## Root-cause analysis (expensive routes)

### 1. `GET /admin/operations/live` — smoking gun

**Controller / services / blade**

- `OperationsDashboardController::live`
- `OperationsDashboardService::dashboardDataForSections`
- `IraOperationsBrainService`, `OperationsAdvisorService`
- `OperationsDashboardLiveRenderer` → section Blade partials under `resources/views/admin/operations/partials/`
- Poller: `resources/js/operations-dashboard.js` (`operations_ms=45000`, full refresh `180000`)

**Dominance:** SQL ~34% of wall (7.1s / 20.8s) + PHP/Blade ~66%. Not external API-bound in the probe (no outbound wait signature in SQL log). Middleware: permission gate `operations-dashboard.view` only — not the cost center.

**N+1 / repeated work (production TOP SQL):**

| Pattern | Count | SQL ms |
|---------|------:|-------:|
| `incident_waiting_states` by incident_id | 1894 | 205 |
| `orders` by id | 1886 | 239 |
| `exists(orders.cashfree_payment_id)` | 346 | 19 |
| `orders.cashfree_payment_id IN (…)`` | 216 | 1437 |
| `orders.order_id IN (…)`` | 216 | 323 |
| `cashfree_webhook_logs.cf_payment_id IN (…)`` | 216 | **3263** |
| `leave_requests` by user/date | 198 | 25 |
| `company_holidays` exists | 110 | 10 |

**Code mechanism (local, matches counts):** `OperationsQueueClassifier` loads `activeWaitingState` / order lazily when relations are not eager-loaded (`activeWaitingState()->first()`), while classifying on the order of ~1.8k open incidents.

**Cache:** DB cache driver — 53 `cache` selects on this path; not the primary cost vs N+1.

### 2. `GET /dashboard/live`

- Wall ~290ms warm; SQL 7–9 / ~2ms → **PHP/Blade dominated**
- Payload **509 KB** every poll
- Poll 30s; Ably configured (may reduce rpm when healthy)

### 3. `GET /admin/automation`

- `AutomationOperationsController@index`
- Wall ~5.1s; SQL 226; Cashfree `IN` scans ×54 ×3 patterns (~1.2s SQL of those)
- Same Cashfree integrity family as ops/live — **duplicate cross-route work**

### 4. `GET /dashboard` SSR

- 0.58–1.75s; 54–93 SQL; 318 KB
- Detail: one query pattern repeated **46×**

### 5. `GET /dashboard/team-activity`

- Cold: 201 SQL / ~500ms SQL time; warm: 43 SQL / still ~0.9s wall (PHP + remaining SQL)

### 6–20. Spec targets

| Area | Finding |
|------|---------|
| `/incidents/*` | Show ~93ms / 41 SQL — not top |
| Customer360 | Show 205ms / 65 SQL; timeline 28ms; device 9ms |
| Search | `search-rows` 4ms / 4 SQL |
| Admin platform / IRA / email / settings | Sub-100ms; DB cache tax only |
| Finance | 70ms / 6 SQL — cheap |

---

## Dominance by layer

| Route | Primary layer | Secondary |
|-------|---------------|-----------|
| `/admin/operations/live` | **DB N+1 + PHP rebuild** | Blade HTML |
| `/dashboard/live` | **PHP / Blade** | Payload size |
| `/admin/automation` | **DB scans + PHP** | Cache |
| `/dashboard` SSR | **PHP + SQL dups** | Blade |
| `/dashboard/team-activity` | **DB (cold) + PHP** | Audit aggregates |
| `/admin/platform` | **Cache→SQL** | — |

---

## Duplicate work across requests

1. **Cashfree integrity** — ops/live and automation both scan payment/order/webhook presence in batches.  
2. **Workforce calendar** — leave / holiday / schedule queries inside ops/live (also related to team-activity domain).  
3. **DB cache driver** — every `Cache::get` is a SQL round-trip (ops SSR, automation, platform).  
4. **Large Blade HTML** — full section HTML re-rendered on each poll for ops/live and dashboard/live.

---

## Estimated CPU savings (HTTP account CPU, Ops tab open)

| Order | Work | Est. save | Risk |
|------:|------|-----------|------|
| 1 | Fix ops/live N+1 + Cashfree on poll path | **40–60%** | Medium — must preserve section HTML / IRA / advisor |
| 2 | Shrink `/dashboard/live` payload | 8–15% continuous | Medium — UI contract |
| 3 | Automation Cashfree scan reuse | Removes ~5s page spikes | Low |
| 4 | Dashboard SSR dedupe + team-activity cache | 2–5% | Low |
| 5 | Redis instead of DB cache | platform/settings/first paint | Infra / ops |

Savings are modeled from measured wall × rpm, not Hostinger panel percentages.

---

## Risk assessment

| Risk | Note |
|------|------|
| False attribution | Without access logs, natural rpm is modeled from poll config + 7 sessions |
| Probe warming | Second ops/live call dropped to 547 SQL / 9.1s — still expensive; cold path still hits when cache cold or full refresh |
| Investigation load | Kernel probes themselves create ops/live work; profile window may include probe traffic |
| Changing ops/live | High product surface (queues, IRA, Cashfree widgets) — needs contract-preserving fix |
| Ably vs poll | If Ably healthy, dashboard/live rpm lower → ops/live share of remaining HTTP rises |

---

## Recommended implementation order

1. **`GET /admin/operations/live`** — eager-load `activeWaitingState` + `order` (and related) before classify; stop per-incident lazy loads; cache or reuse `CashfreeIntegrityReadModel` results across poll ticks; avoid full heavy bundles every 45s when section groups allow.  
2. **`GET /dashboard/live`** — reduce 509 KB HTML (partials / JSON rows).  
3. **`GET /admin/automation`** — share Cashfree integrity cache with ops.  
4. Dashboard SSR duplicate query + team-activity cold path.  
5. Infra: Redis cache driver.

**Do not implement until explicitly approved.**

---

## Additional production measurements required

To close PID→URL and true p95 gaps:

1. Enable LiteSpeed/Hostinger access logging temporarily, **or**  
2. Add short-lived middleware logging: `request_id`, method, path, user_id, `wall_ms`, `sql_count`, `sql_ms`, `response_bytes`, `memory_peak` (sample or always for admin routes).  
3. Correlate middleware timestamps with `ps` lsphp PIDs during a natural spike (no synthetic ops/live probes in that window).

---

## Probe appendix (raw)

```
POLL_DASH_LIVE_MS=30000 POLL_NOTIF_MS=45000 POLL_OPS_MS=45000
POLL_OPS_FULL_MS=180000 POLL_C360_TL_MS=45000
CACHE=database BROADCAST=ably SESS_AUTH_15M=7

GET /admin/operations/live  ms=[20520.3,9058] sql=[5660,547] bytes=154823
  detail heavy: sql=5966 sql_ms=7131 wall=20832 mem_peak=394MB
GET /admin/automation       ms=5019–5076 sql=226 bytes~234KB
GET /dashboard/live         ms=[290,287,298] sql=[9,7,7] bytes=509080
GET /dashboard              ms=[1747,578] sql=[93,54] bytes=318132
GET /dashboard/team-activity ms=[1021,883] sql=[201,43] bytes=105542
GET /admin/operations       ms=234–319 sql=74–94 bytes=67230
GET /notifications/poll     ms~68 sql=6 bytes=6169
Customer360 show/timeline/device: 205ms/28ms/9ms
search-rows: 3.7ms / 4 SQL
```

Profile log: `/tmp/lsphp-http-profile-20260807T124034Z.log` on production host.
