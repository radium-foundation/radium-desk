# P0 Production CPU — Request Inventory & Attribution

**Status:** Phase 1 code shipped (local); production re-measure pending deploy  
**Date:** 2026-08-07  
**Method:** Code inventory + production SSH probes (`tools/config.sh` → `desk.radiumbox.com`) + local warm-path budget test  
**Canvas:** [`p0-production-cpu-request-inventory.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-production-cpu-request-inventory.canvas.tsx)  
**Companion polling matrix:** [periodic-polling-endpoints-investigation.md](./periodic-polling-endpoints-investigation.md)

---

## Verdict

Hostinger CPU saturation is **cost-dominated by `/dashboard/live`**, not by raw RPS alone.

Even with **Ably connected** and live polling in **heartbeat mode (60s)**, each live request still costs **~1–2.5s**, **~5k–8.5k DB queries**, and **~500 KB** JSON — primarily because `CACHE_STORE=database` turns every `SettingService` / RadiumBox cache lookup into a SQL `SELECT`, and SLA classification walks ~**995** active incidents.

Secondary: **`/api/webhooks/interakt`** (~4.3/min) runs **synchronous unbounded outbox drain** in the HTTP request, with received→processed delays of **54–168s** under burst. Tertiary: **`/notifications/poll`** at 20s on every authenticated page (~24/min) — cheap per call, high volume.

---

## Production facts (live probe)

| Fact | Value |
|------|------:|
| Host load (1/5/15) | 20.15 / 24.73 / 22.15 |
| Active users | 14 |
| `incidents.view` users | 12 |
| Sessions last 15m / 60m | 8 / 8 |
| Non-terminal incidents | 995 |
| `cache.default` | `database` |
| Broadcast | `ably` (key set; Echo configured) |
| Realtime transport | `auto` (Ably healthy → heartbeat) |
| Connection status cache | `status=connected`, `polling_active=false` |
| Performance profile | balanced |
| Live active / idle (realtime settings) | **20s / 60s** |
| Notification poll | **20s** |
| Snapshot cache | `operator.dashboard.snapshot:v2` present |
| Slow scalars cache | present |

---

## Ranked table (deliverable)

| Endpoint | Requests/min | Avg ms | P95 ms | DB queries | CPU impact | Business criticality | Recommended interval | Optimization priority |
|----------|-------------:|-------:|-------:|-----------:|------------|----------------------|----------------------|-----------------------|
| `GET /dashboard/live` | **5–15** | **1475** | **~2400** | **5089–8512** | **Critical** | Critical | Keep 60s heartbeat; fallback **30s** (not 20s) | **P0** |
| `POST /api/webhooks/interakt` | **4.3** (peak 21) | ~20–200* | **100000+*** | 14–22+ | **High** | Critical | N/A (push) — fix sync drain | **P0** |
| `GET /notifications/poll` | **24** | **61** | **~62** | **7** | Medium | High | **45–60s** or pause when Echo connected | **P1** |
| `GET /dashboard/team-activity` | 0–6 | n/m | n/m | high (≤120 in tests) | High when expanded | Medium | **60s** while expanded | **P1** |
| `GET /dashboard/activity` | ~12 | n/m | n/m | medium | Medium | Low–Med | Merge into live / **60–120s** | **P1** |
| `POST /presence/heartbeat` | 4 | n/m | n/m | low | Low | High (WFM) | Keep **120s** | P2 |
| `GET /admin/operations/live` | 0–2 | n/m | n/m | section-cached | Medium | High (admin) | 45–60s / full 180s | P2 |
| C360 timeline / device | 0–10 | n/m | n/m | high timeline | Medium | Medium | 45–60s; pause when hidden | P2 |
| Email thread poll | 0–3 | n/m | n/m | medium | Low–Med | High while open | 30–45s | P2 |

\*Interakt: synthetic store+`processAggregate` ≈ **20ms**. Production `received_at→processed_at`: most same-second; **15/269** in last hour delayed **54–168s** (outbox lock / CPU starvation).

**Measurement notes**

- Live + notification timings: internal `HttpKernel` probe as user id=1, 3 samples each (2026-08-07 ~06:32 UTC).
- Live samples: 2433ms / 1028ms / 964ms; queries 8507 → 5089 → 5089; body **510946 bytes**.
- Notification samples: ~60ms, 7 queries, ~6.3 KB.
- Req/min: modeled from 8 sessions + Ably heartbeat; Hostinger access logs not exposed on this account.

---

## Request rate estimate

### Current (Ably healthy, ~8 sessions, ~6 dashboard tabs)

| Source | Req/min |
|--------|--------:|
| `/notifications/poll` @ 20s × 8 | 24 |
| `/dashboard/live` heartbeat @ 60s × 6 | 6 |
| `/dashboard/activity` @ 30s × 6 | 12 |
| `/presence/heartbeat` @ 120s × 8 | 4 |
| `/api/webhooks/interakt` | 4.3 |
| Team Activity / OCC / C360 / misc | 0–8 |
| **Total** | **≈ 48–55** |

Ably **fallback** (20s live): +10–15 → **≈ 60–70 req/min**.

### Optimized (recommendations only — not applied)

| Change | Effect |
|--------|--------|
| Notifications 45–60s or Ably-gated | 24 → 8–10 |
| Merge My Activity into live | 12 → 0–3 |
| Team Activity 60s | 6 → 3 |
| Pause C360 when hidden | −2–4 |
| **Total** | **≈ 22–28 req/min** |

**Important:** Cutting live from ~8.5k queries to &lt;100 (Redis + request-memo settings + batched RadiumBox) is a larger CPU win than the RPM cut.

---

## Periodic endpoint inventory (for every polled URL)

| # | URL | Caller | Poll interval | Avg ms | P95 ms | DB queries | Cache hits/misses | Response size | Overlap | Multi-tab duplicates |
|---|-----|--------|---------------|-------:|-------:|-----------:|-------------------|--------------:|---------|----------------------|
| 1 | `GET /dashboard/live` | Operator Dashboard (`live-dashboard-polling.js`) | Heartbeat **60s** (300s after 5m idle); fast fallback **20s**; hidden pauses | 1475 | ~2400 | 5089–8512 | Snapshot hit warm; **6442×** `app.settings.all` DB-cache gets; email widget cache | ~499 KB | Yes (notif/activity/presence) | **Yes** — no leader election |
| 2 | `GET /notifications/poll` | Global navbar (`live-notifications.js`) | **20s**; fetch skipped if hidden | 61 | ~62 | 7 | None | ~6 KB | Yes | **Yes** |
| 3 | `POST /presence/heartbeat` | Global shell | **120s** + visibility | n/m | n/m | low | — | &lt;1 KB | Low | **Yes** |
| 4 | `GET /dashboard/activity` | My Activity | **30s** | n/m | n/m | medium | None | 5–40 KB est. | Yes w/ live | **Yes** |
| 5 | `GET /dashboard/team-activity` | Team Activity (expanded) | **30s** | n/m | n/m | high | None | 20–150 KB est. | Yes | **Yes** |
| 6 | `GET /admin/operations/live` | OCC | 30s / full 120s | n/m | n/m | section cache | 30s section | 30–200 KB est. | Admin | **Yes** |
| 7 | `GET /admin/platform/zones/{zone}` | Platform | 60s stale/priority | n/m | n/m | zone snapshots | Redis-recommended; DB today | 5–80 KB | Admin | **Yes** |
| 8 | C360 timeline refresh | Customer360 drawer | **30s** | n/m | n/m | high | Request-only | 10–80 KB | With live if open | **Yes** |
| 9 | C360 device sync | Customer360 | **10s** while syncing | n/m | n/m | medium | — | 5–30 KB | With live | **Yes** |
| 10 | Email thread | Email workspace | **20s** hardcoded | n/m | n/m | medium | — | 5–100 KB | With C360 | **Yes** |

**Event-driven (not periodic):** `GET /dashboard/live/rows` (Ably row patch).

**Intervals source:** Blade uses `RealtimeRuntimeConfig` (`realtime.polling_interval_*` → **20s/60s**), not `performance.polling.dashboard_live_ms` (30s balanced). Notifications use `performance.polling.notification_ms` = 20000.

---

## `/dashboard/live` deep dive

### Route / controller

- `routes/web.php` → `DashboardLiveController::refresh`
- Auth middleware + `TrackTeamMemberActivity` (throttled `last_active_at` + presence)

### Widgets executed every request

| Work | Service | Notes |
|------|---------|-------|
| Fast KPIs | `DashboardService::fastChangingStatsFor` | Online users, queue KPIs, refunds/approvals, email widget, SLA counts |
| Slow scalars | `slowChangingStatsFor` | Cached `Order`/`User`/`AuditLog` counts |
| Filter counts | `CaseQueueReadModel::filterCounts` | Over full active snapshot |
| Case rows (≤35 HTML) | `serviceCasesPayload` → Blade `service-case-row` | ~232 KB of row HTML in probe |
| KPI strip HTML | `renderKpiStrip` | ~8 KB |

Team Activity is **not** in the live JSON (separate endpoint).

### Queries that dominate CPU (production breakdown, one request)

| Rank | Pattern | Count | Total ms |
|------|---------|------:|---------:|
| 1 | `SELECT * FROM cache WHERE key IN (?)` → `app.settings.all` | **6442** | ~455 |
| 2 | `orders` RadiumBox sync columns by id | **777** | ~59 |
| 3 | `COUNT(*)` `audit_logs` (cold slow-scalar) | 1 | ~52 |
| 4 | Close outcomes / assignment audit helpers | tens | low |

**Root of #1:** `Incident::slaStatus()` calls `SettingService::getInt` (2× per call). `SettingService::remember()` uses `Cache::rememberForever('app.settings.all')`. With **database** cache, every get = SQL. Classification/KPI/SLA walks hundreds of incidents → thousands of cache SELECTs. No request-scoped memoization.

**Root of #2:** RadiumBox enrichment sync store loads order sync status per order id during row/badge work across the active population (777 unique order ids observed).

### Identical consecutive polls

- **No ETag / If-None-Match / content hash.**
- Snapshot TTL 15–30s means consecutive heartbeats often rebuild **identical** KPI HTML + filter counts + row HTML.
- Client always applies full payload (no 304 short-circuit).

### Fan-out

One live request fans into: snapshot decode → KPI aggregator → CaseQueue filters → email widget → online users → per-row Blade (commercial state, verification, badges) → JSON encode ~500 KB. Plus middleware presence/activity.

**No external HTTP** on `/dashboard/live`. Internal cost is O(all active incidents) even on snapshot cache hit:

- `DashboardSnapshot::warmQueueIncidents()` / `filterCounts()` — classify + count all queues + legacy filters
- `slaCounts()` — SLA status per active incident (drives `SettingService` stampede)
- Admin: `ServiceCaseAutomationHealthService::countsFor()` — `statusFor()` over **all** active incidents (may touch RadiumBox sync store)
- Rows: `CommercialStateResolver::forIncident()` → `loadMissing(['closeOutcomes…'])` **per row** (not in snapshot eager load) — N+1 candidate for the visible page (default 35)

### Dead / duplicate work on the live path

| Issue | Detail |
|-------|--------|
| Unused KPI computation | `automation_health`, `approval_numbers`, `pending_approvals`, full hardware/service SLA splits, `incidentStatusCounts` are built in `fastChangingStatsFor()` but **not rendered** in `kpi-strip.blade.php` |
| Duplicate workspace resolve | Controller calls `OperationsWorkspaceResolver`; `liveMetricsFor()` calls `resolveLiveDashboardContext()` again |
| Payload duplication | `fast.rows` / `fast.incident_ids` duplicate top-level `rows` / `incident_ids` in the JSON |
| Config drift | Blade intervals use `realtime.polling_interval_*` (20s/60s); `performance.polling.dashboard_live_ms` (30s) is **not** wired to live JS |

### Cache opportunities (recommendations only)

1. Redis (or array/request memo) for `app.settings.all` / `SettingService`.
2. Batch RadiumBox sync status for incident set; eager-load `closeOutcomes` on snapshot/rows.
3. Conditional GET / hash short-circuit when snapshot version unchanged.
4. Skip unused admin aggregates on the live poll path; split poll: rows vs slow KPI HTML.
5. Keep Ably healthy — fallback multiplies rate ×3.

---

## Interakt investigation

### Route

`POST /api/webhooks/interakt` → `InteraktWebhookController::handle`  
Sibling: `POST /api/webhooks/interakt/flow` (uses `processAggregate` — better).

### Frequency (production DB)

| Window | Count | Est. rpm |
|--------|------:|---------:|
| 5m | 6 | 1.2 |
| 15m | 59 | 3.9 |
| 60m | 260 | **4.3** |
| 24h | 3735 | ~2.6 |

Event mix (60m): `message_api_sent` 93, `delivered` 91, `read` 74, `failed` 2 — **~3–4 webhooks per outbound template lifecycle**.

### Processing model

```
log (INFO full body) → INSERT interakt_webhook_logs
→ outbox firstOrCreate
→ OutboxProcessorService::process()   // SYNC, NO LIMIT, global FIFO
→ InteraktWebhookProcessorService → upsert interakt_messages by message_id
→ 200 OK
```

| Topic | Finding |
|-------|---------|
| Queue vs sync | **Synchronous** in webhook HTTP; also cron `outbox:process` every minute |
| External API | **None** on inbound path |
| DB writes | webhook log, outbox claim/complete, message upsert |
| Duplicates | New log per POST; message **idempotent** on `message_id` |
| Retry | Outbox max 5, backoff 30/120/600/1800s; Interakt does not redeliver failures |
| Slowest path | Unbounded global drain (can process Cashfree/Bonvoice/email/outbound WhatsApp while holding webhook) |
| Failed outbox backlog | **2778** failed (2742 Cashfree deferred, 36 Bonvoice) — not Interakt, but lengthens FIFO when pending |

### Latency evidence

- Synthetic (rolled back): store + `processAggregate` ≈ **20ms**, 14 queries.
- Production delays: clustered bursts with **54–168s** received→processed — consistent with lock wait / CPU contention while many webhooks call `process()` concurrently.

Docs claim “return 200 quickly”; **code does not** — it drains outbox before responding (`InteraktWebhookController` L45–46).

---

## Overlap & multi-tab

| Behavior | Detail |
|----------|--------|
| Overlap | Dashboard tab runs live + notifications + presence + activity (+ team activity if expanded). Timers are independent; live has `refreshInFlight` coalesce **per tab only**. |
| Multi-tab | **Linear multiplication.** No `BroadcastChannel` / shared worker leader election anywhere. |
| Hidden tabs | Live heartbeat pauses; notifications/presence/activity **skip fetch** but timers keep firing; C360/email **continue**. |

---

## Highest-value optimization ranking (recommendations only)

| Priority | Action | Expected impact |
|----------|--------|-----------------|
| P0 | Redis (or request memo) for `SettingService` / stop DB-cache stampede on live | Remove ~6400 queries/live |
| P0 | Batch RadiumBox sync reads; eager-load `closeOutcomes` for row render | Remove ~777+ N+1 SELECTs/live |
| P0 | Interakt: `processAggregate` (or ack-then-cron) instead of unbounded `process()` | Cut webhook CPU & 3s SLA risk |
| P1 | Drop unused live aggregates (`automation_health`, unused SLA/approval splits) | Less CPU per poll for admins |
| P1 | Pause/slow `/notifications/poll` when Ably delivers | −50–70% notification RPS |
| P1 | Merge My Activity into live payload | −12 req/min |
| P1 | Live ETag / snapshot version short-circuit | Skip Blade when unchanged |
| P2 | Raise fallback live interval 20s → 30s | −33% live RPS in outage |
| P2 | Pause C360/email polls when `document.hidden` | Cut idle drawer load |

---

## Phase 1 — `/dashboard/live` performance (implemented)

Performance-only. No Redis, polling, Reverb, Ready Queue, UX, or business-rule changes. JSON contract preserved.

### Before (production probe, 2026-08-07)

| Metric | Value |
|--------|------:|
| Execution time | **964–2433 ms** (avg ~1475) |
| SQL queries | **5089–8512** |
| Payload | **~499–511 KB** |
| Top SQL | **6442×** `cache` SELECT `app.settings.all`; **777×** RadiumBox order sync SELECTs |

### After (local warm budget — `DashboardLivePhase1PerformanceTest`, 40 open cases)

| Metric | Value |
|--------|------:|
| Execution time | **~8 ms** (SQLite / warm snapshot; not a prod substitute) |
| SQL queries | **5** (&lt;100 budget) |
| Cache SELECTs | **0** (&lt;20 budget) |
| Payload | **~8.6 KB** (fixture scale; prod still dominated by row HTML) |

### Measured improvements (expected on production after deploy)

| Driver | Change | Expected SQL effect |
|--------|--------|---------------------|
| SettingService stampede | Request-scoped memo + `scoped()` binding | **~6442 → ≤1** `app.settings.all` cache SELECT per request |
| RadiumBox sync N+1 | Prefer order columns; `warmFromOrders()` before row render | **~777 → 0** per-id order/cache lookups on live rows |
| Commercial State N+1 | Batch `loadMissing` refund/close relations for visible rows | Per-row refund/outcome SELECTs → **few batched** queries |
| Unused admin aggregates | Lean KPI path skips `automation_health`, unused SLA/approval splits | Removes full-active-set automation health walk on live |
| Duplicate context | Pass `assignedToForFilterCounts` into `liveMetricsFor` | One workspace/assigned-to resolve per refresh |

**Payload:** still large in production while row HTML is embedded in JSON (~232 KB of rows in the original probe). Phase 1 did not remove API fields.

### Files changed

| File | Change |
|------|--------|
| `app/Services/SettingService.php` | Request-scoped `$resolved` memo; cleared in `forget()` |
| `app/Providers/AppServiceProvider.php` | `$this->app->scoped(SettingService::class)` |
| `app/Services/RadiumBox/RadiumBoxOrderEnrichmentSyncStore.php` | Preloaded-order short-circuit; `warmFromOrders()` |
| `app/Services/ServiceCaseAutomationHealthService.php` | Pass `$incident->order` into sync `status()` |
| `app/Services/DashboardService.php` | Lean KPI strip stats; batch row `loadMissing` + RadiumBox warm; reuse assigned-to in `liveMetricsFor` |
| `app/Services/Dashboard/DashboardSnapshotStore.php` | Eager-load nested refund actors on snapshot (not `closeOutcomes` — alias gap) |
| `app/Http/Controllers/DashboardLiveController.php` | Pass `assignedToForFilterCounts` |
| `tests/Unit/Services/SettingServiceRequestMemoTest.php` | Memo regression |
| `tests/Feature/DashboardLivePhase1PerformanceTest.php` | Warm live query budget (&lt;100 queries, &lt;20 cache SELECTs) |

### Remaining bottlenecks / P0 items

| Item | Why it remains |
|------|----------------|
| **Production re-benchmark** | Confirm &lt;300 ms / &lt;100 queries on Hostinger after deploy |
| **Payload size (~500 KB)** | Row HTML in JSON; needs ETag/304 or row-diff / lighter row payload (P1) |
| **PHP walk of ~995 active incidents** | Snapshot filter/SLA classification still O(active set) CPU even with 1 settings read |
| **Interakt `process()` unbounded sync drain** | Separate P0 — not touched in Phase 1 |
| **`/notifications/poll` @ 20s** | Volume P1; cheap per call |
| **Redis for cache store** | Optional later; Phase 1 memo removes the stampede without Redis |
| **My Activity / Team Activity overlap** | Separate pollers (P1) |

### Regression (local)

Focused suite after Phase 1: **67 passed, 1 skipped** (`SettingServiceRequestMemo`, live budget, OperatorDashboard cache, Commercial State, Customer360 drawer, Ready Queue SLA/visibility, email intake counters, Finance foundation, live row visibility).

Pre-existing failures (also fail on clean `main`, unrelated to Phase 1): `DashboardServiceCasesTest` (expects obsolete “Waiting for Service Reference” copy), some `OperationsDashboardTest` / `DashboardReverbMetricsConsistencyTest` assertions.

---

## Sources

- Production SSH probes 2026-08-07 (tinker HttpKernel timing, query log grouping, Interakt counts)
- Local Phase 1 warm budget: `tests/Feature/DashboardLivePhase1PerformanceTest.php`
- `app/Http/Controllers/DashboardLiveController.php`
- `app/Services/DashboardService.php`, `OperatorDashboardCache.php`
- `app/Models/Incident.php` (`slaStatus` → `SettingService`)
- `app/Services/SettingService.php` (`app.settings.all`)
- `app/Http/Controllers/Webhooks/InteraktWebhookController.php`
- `app/Services/Outbox/OutboxProcessorService.php`
- `resources/js/live-dashboard-polling.js`, `live-notifications.js`
- Related: [radium-desk-performance-audit.md](./radium-desk-performance-audit.md), [periodic-polling-endpoints-investigation.md](./periodic-polling-endpoints-investigation.md)
