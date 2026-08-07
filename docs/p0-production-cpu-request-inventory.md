# P0 Production CPU — Request Inventory & Attribution

**Status:** Phase 1–8 shipped (Phase 8 = event-driven automation snapshot infrastructure)  
**Date:** 2026-08-07  
**Method:** Code inventory + production SSH probes (`tools/config.sh` → `desk.radiumbox.com`) + local warm-path / webhook / watchdog / JS poller / RadiumBox queue / automation snapshot / platform warm tests  
**Canvas:** [`p0-production-cpu-request-inventory.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-production-cpu-request-inventory.canvas.tsx)  
**Phase 7 canvas:** [`p0-platform-snapshots-warm-optimization.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-platform-snapshots-warm-optimization.canvas.tsx)  
**Post-deploy remeasure:** [p0-production-remeasure-after-optimizations.md](./p0-production-remeasure-after-optimizations.md)  
**Companion polling matrix:** [periodic-polling-endpoints-investigation.md](./periodic-polling-endpoints-investigation.md)

---

## Verdict

After Phase 1 deploy, production remasure shows Hostinger account CPU is **cron-dominated**. The largest single consumer was **`platform:snapshots:warm` (~11.6s / ~28% account CPU every minute)** — Phase 7 cuts that amortized cost by **~80%+** (dedupe + skip-when-fresh).

Earlier inventory (pre–Phase 1) correctly attributed live polls: `/dashboard/live` was **~1–2.5s / 5k–8.5k SQL** via `SettingService` stampede + RadiumBox N+1; Phase 1 fixed that path. Secondary: **`/api/webhooks/interakt`** — Phase 2 enqueue-only. Cron **`watchdog:send-critical-alerts`** — Phase 3. **`/notifications/poll`** — Phase 4. **`queue:work` / RadiumBox** — Phase 5. Cron **`automation:snapshot`** — Phase 6.

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
| `POST /api/webhooks/interakt` | **4.3** (peak 21) | ~20–200* → **~15ms local after Phase 2** | **100000+*** (pre–Phase 2) | 14–22+ → **~3 ack** | **High → Low after Phase 2** | Critical | N/A (push) — enqueue-only + cron | **P0 done** |
| `GET /notifications/poll` | **24 → ~8** | **61** | **~62** | **7** | Medium → Low | High | **45s** / **60s** Ably safety-net; pause hidden | **P1 done** |
| `GET /dashboard/team-activity` | 0–6 → 0–3 | n/m | n/m | high (≤120 in tests) | High when expanded | Medium | **60s** while expanded; pause hidden | **P1 done** |
| `GET /dashboard/activity` | ~12 → 0–1 | n/m | n/m | medium | Medium | Low–Med | **60s** (legacy); pause hidden | **P1 done** |
| `POST /presence/heartbeat` | 4 | n/m | n/m | low | Low | High (WFM) | Keep **120s**; pause timer hidden | **P2 done** |
| `GET /admin/operations/live` | 0–2 → ~1.3 | n/m | n/m | section-cached | Medium | High (admin) | **45s** / full **180s** | **P2 done** |
| C360 timeline / device | 0–10 → 0–5 | n/m | n/m | high timeline | Medium | Medium | **45s / 15s**; pause when hidden | **P2 done** |
| Email thread poll | 0–3 → ~2 | n/m | n/m | medium | Low–Med | High while open | **30s**; pause hidden | **P2 done** |

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

### Optimized (Phase 4 polling applied — `/dashboard/live` unchanged)

| Change | Effect |
|--------|--------|
| Notifications: pause when hidden + 60s safety-net while Ably connected; balanced default 45s | 24 → **8** |
| My Activity: 60s + pause when hidden (legacy path only; Team Activity is primary) | 12 → **0–1** |
| Team Activity: 60s + stop timer when hidden | 6 → **3** |
| OCC: 45s / full 180s (already paused when hidden) | 2 → **~1.3** |
| C360 timeline/device + email: slower defaults + pause when hidden; email refresh resets timeline timer | 0–10 → **0–5** |
| Presence: stop interval while hidden (catch-up on visible) | 4 → **4** (visible) / **0** (hidden) |
| **Total (visible, Ably healthy)** | **≈ 26–32 req/min** |
| **With typical hidden/background tabs** | **≈ 18–26 req/min** |

| Metric | Current (pre–Phase 4) | Optimized (Phase 4) |
|--------|----------------------:|--------------------:|
| Periodic req/min (8 sessions, Ably OK) | **≈ 48–55** | **≈ 26–32** |
| Notification share | 24 | 8 |
| `/dashboard/live` | 6 (heartbeat) | 6 (unchanged) |

**Important:** Cutting live from ~8.5k queries to &lt;100 (Phase 1 memo + warm path) remains the larger CPU win per request; Phase 4 cuts always-on RPS without changing live UX.

---

## Periodic endpoint inventory (for every polled URL)

| # | URL | Caller | Poll interval | Avg ms | P95 ms | DB queries | Cache hits/misses | Response size | Overlap | Multi-tab duplicates |
|---|-----|--------|---------------|-------:|-------:|-----------:|-------------------|--------------:|---------|----------------------|
| 1 | `GET /dashboard/live` | Operator Dashboard (`live-dashboard-polling.js`) | Heartbeat **60s** (300s after 5m idle); fast fallback **20s**; hidden pauses | 1475 | ~2400 | 5089–8512 | Snapshot hit warm; **6442×** `app.settings.all` DB-cache gets; email widget cache | ~499 KB | Yes (notif/activity/presence) | **Yes** — no leader election |
| 2 | `GET /notifications/poll` | Global navbar (`live-notifications.js`) | **45s** (balanced); **60s** Ably safety-net; timer paused when hidden | 61 | ~62 | 7 | None | ~6 KB | Yes | **Yes** |
| 3 | `POST /presence/heartbeat` | Global shell | **120s**; timer paused when hidden | n/m | n/m | low | — | &lt;1 KB | Low | **Yes** |
| 4 | `GET /dashboard/activity` | My Activity (legacy) | **60s**; timer paused when hidden | n/m | n/m | medium | None | 5–40 KB est. | Yes w/ live | **Yes** |
| 5 | `GET /dashboard/team-activity` | Team Activity (expanded) | **60s**; timer paused when hidden | n/m | n/m | high | None | 20–150 KB est. | Yes | **Yes** |
| 6 | `GET /admin/operations/live` | OCC | **45s** / full **180s**; paused when hidden | n/m | n/m | section cache | 30s section | 30–200 KB est. | Admin | **Yes** |
| 7 | `GET /admin/platform/zones/{zone}` | Platform | 60s stale/priority | n/m | n/m | zone snapshots | Redis-recommended; DB today | 5–80 KB | Admin | **Yes** |
| 8 | C360 timeline refresh | Customer360 drawer | **45s**; paused when hidden | n/m | n/m | high | Request-only | 10–80 KB | With live if open | **Yes** |
| 9 | C360 device sync | Customer360 | **15s** while syncing; paused when hidden | n/m | n/m | medium | — | 5–30 KB | With live | **Yes** |
| 10 | Email thread | Email workspace | **30s**; paused when hidden | n/m | n/m | medium | — | 5–100 KB | With C360 | **Yes** |

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

### Processing model (before Phase 2)

```
log (INFO full body) → INSERT interakt_webhook_logs
→ outbox firstOrCreate
→ OutboxProcessorService::process()   // SYNC, NO LIMIT, global FIFO
→ InteraktWebhookProcessorService → upsert interakt_messages by message_id
→ 200 OK
```

| Topic | Finding |
|-------|---------|
| Queue vs sync | **Was synchronous** in webhook HTTP; also cron `outbox:process` every minute |
| External API | **None** on inbound path |
| DB writes | webhook log, outbox claim/complete, message upsert |
| Duplicates | New log per POST; message **idempotent** on `message_id` |
| Retry | Outbox max 5, backoff 30/120/600/1800s; Interakt does not redeliver failures |
| Slowest path | Unbounded global drain (could process Cashfree/Bonvoice/email/outbound WhatsApp while holding webhook) |
| Failed outbox backlog | **2778** failed (2742 Cashfree deferred, 36 Bonvoice) — not Interakt, but lengthened FIFO when pending |

### Latency evidence (pre–Phase 2)

- Synthetic (rolled back): store + `processAggregate` ≈ **20ms**, 14 queries.
- Production delays: clustered bursts with **54–168s** received→processed — consistent with lock wait / CPU contention while many webhooks call `process()` concurrently.

Docs claimed “return 200 quickly”; code drained the outbox before responding until Phase 2.

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
| P0 | ~~Interakt: `processAggregate` (or ack-then-cron) instead of unbounded `process()`~~ **Done (Phase 2: enqueue-only)** | Cut webhook CPU & 3s SLA risk |
| P0 | ~~`watchdog:send-critical-alerts` (~21s)~~ **Done (Phase 3)** | Remove ~10% account CPU cron stall |
| P0 | ~~`queue:work` RadiumBox duplicate jobs / retry storms~~ **Done (Phase 5)** | Cut enrichment HTTP + critical-queue CPU |
| P0 | ~~`automation:snapshot` (~6.5s / 28k SQL)~~ **Done (Phase 6)** | Remove ~16% account CPU cron stall |
| P1 | Drop unused live aggregates (`automation_health`, unused SLA/approval splits) | Less CPU per poll for admins |
| P1 | ~~Pause/slow `/notifications/poll` when Ably delivers~~ **Done (Phase 4)** | −50–70% notification RPS |
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
| ~~**Interakt `process()` unbounded sync drain**~~ | **Fixed in Phase 2** (enqueue-only + cron) |
| ~~**`watchdog:send-critical-alerts` ~21s**~~ | **Fixed in Phase 3** (in-process `/up`, lean collectors, Cashfree batch) |
| ~~**`automation:snapshot` ~6.5s / 28k SQL**~~ | **Fixed in Phase 6** (Cashfree batch + fingerprint skip) |
| ~~**`queue:work` RadiumBox duplicates / retry storms**~~ | **Fixed in Phase 5** (unique job, Pending skip, attempt preserve, lookup cache) |
| ~~**`/notifications/poll` @ 20s**~~ | **Fixed in Phase 4** (45–60s + Ably/hidden pause) |
| **Redis for cache store** | Optional later; Phase 1 memo removes the stampede without Redis |
| **My Activity / Team Activity overlap** | Separate pollers (P1) |

### Regression (local)

Focused suite after Phase 1: **67 passed, 1 skipped** (`SettingServiceRequestMemo`, live budget, OperatorDashboard cache, Commercial State, Customer360 drawer, Ready Queue SLA/visibility, email intake counters, Finance foundation, live row visibility).

Pre-existing failures (also fail on clean `main`, unrelated to Phase 1): `DashboardServiceCasesTest` (expects obsolete “Waiting for Service Reference” copy), some `OperationsDashboardTest` / `DashboardReverbMetricsConsistencyTest` assertions.

---

## Phase 2 — Interakt webhook enqueue-only (implemented)

Performance-only. No business-rule, UI, or new queue framework.

### Root cause

`InteraktWebhookController` persisted the webhook + outbox row, then called **`OutboxProcessorService::process()` with no limit**. That drained the **global** pending outbox (Cashfree deferred, Bonvoice, email, WhatsApp outbound, other Interakt items) inside the HTTP request before returning 200. Under burst this starved PHP workers and delayed `received_at→processed_at` by **54–168s**.

### Architecture before

```
POST /api/webhooks/interakt
  → validate / store interakt_webhook_logs
  → outbox firstOrCreate (idempotent)
  → OutboxProcessorService::process()   // unbounded global FIFO, sync
  → 200 OK
```

Sibling `POST /api/webhooks/interakt/flow` already used `processAggregate()` (scoped sync) — safer, still in-request.

### `processAggregate()` investigation

| Option | Safe? | Meets &lt;100ms? | Drains unrelated? | Async? |
|--------|-------|------------------|-------------------|--------|
| `process()` (old) | No under load | Often no | **Yes — global** | No |
| `processAggregate(interakt_webhook_log, id)` | **Yes** (same as flow / email) | **Yes** (~20ms synthetic) | No | No (still sync in HTTP) |
| **Enqueue only** (chosen) | **Yes** | **Yes** (~15ms / 3 queries local) | No | **Yes** — cron `outbox:process` |

`processAggregate()` would have been a correct minimal fix for unrelated-drain. Phase 2 chooses **enqueue-only** to meet “return 200 immediately” and “only enqueue” without holding the webhook worker for processor work. Existing outbox idempotency, retries, ordering (`orderBy id`), and audit/webhook logs are unchanged.

### Architecture after

```
POST /api/webhooks/interakt
  → validate / store interakt_webhook_logs (status=received)
  → outbox firstOrCreate (pending)
  → 200 OK immediately

Cron every minute: outbox:process
  → claim pending FIFO → InteraktWebhookProcessorService
  → upsert interakt_messages (idempotent by message_id)
```

Flow webhook unchanged (`processAggregate` — needs sync validation/422 semantics).

### Benchmarks

| Metric | Before (prod / synthetic) | After (local enqueue-only) |
|--------|---------------------------|----------------------------|
| Webhook response time | 20ms–100s+ under burst (global drain) | **~15 ms** (`InteraktWebhookEnqueueOnlyPerformanceTest`) |
| SQL queries (ack path) | 14+ and unbounded if drain runs | **3** |
| Unrelated outbox drained in request | **Yes** | **No** (asserted in test) |
| CPU on webhook workers | High during bursts | Persist + enqueue only |
| Message processing latency | Same-request when healthy; 54–168s when contended | Up to **~60s** via `outbox:process` (existing cron) |

Production re-measure pending deploy (response time + Hostinger load during Interakt bursts).

### Files changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Webhooks/InteraktWebhookController.php` | Remove sync `process()`; enqueue-only then 200 |
| `tests/Support/InteractsWithInteraktWebhooks.php` | `drainOutbox` / `postInteraktWebhookAndDrain` helpers |
| `tests/Feature/InteraktWebhookTest.php` | Assert enqueue-then-drain; retry expectations for async |
| `tests/Feature/InteraktWebhookSignatureTest.php` | Drain after signed ack when asserting processed |
| `tests/Feature/WhatsAppConversationFeatureTest.php` | Drain after webhook posts |
| `tests/Feature/InteraktWebhookEnqueueOnlyPerformanceTest.php` | &lt;100ms budget + no unrelated drain |

### Rollback notes

1. Restore the single call in `InteraktWebhookController::handle` after `writeProcessingJob`:

   ```php
   $this->outboxProcessorService->process();
   ```

   (re-inject `OutboxProcessorService` in the constructor).

2. No migration / schema change — outbox rows already pending if unprocessed.

3. Cron `outbox:process` remains the safety net either way; rollback only reintroduces in-request global drain.

### Regression (local)

Core Interakt + Outbox + signature + enqueue budget: **31/34** in `InteraktWebhook*` / `InteraktFlowWebhook*` / `OutboxProcessing*` (3 failures are **pre-existing** WhatsApp timeline presentation asserts — messages still persist via outbox drain). Broader Notification/Webhook filter noise includes unrelated pre-existing failures (email templates, scheduler hardening, Bonvoice C360 HTML).

---

## Phase 3 — `watchdog:send-critical-alerts` (implemented)

Performance-only. Alert keys, thresholds, fingerprint gate semantics, and Telegram copy unchanged.

### Before (production, 2026-08-07)

| Metric | Value |
|--------|------:|
| Wall time | **21208 ms** |
| Schedule | every **5** minutes |
| Est. account CPU share | **~10%** (cost units 4242 in TOP20 model) |
| Dominant stall | Outbound `Http::timeout(10)->retry(2)` to `app.url/up` under load → ~2×10s timeouts |

### Root causes

| Issue | Detail |
|-------|--------|
| Self-HTTP site probe | `siteHealthAlerts()` called production URL with 10s timeout + 2 retries; under CPU starvation this alone ≈ **21s** and amplified load |
| Duplicate integration scan | `interaktAlerts()` called `integrationHealthService->cards()` → rebuilt Cashfree + Gmail + ZeptoMail + Telegram (incl. up to **2000** `audit_logs`) every tick for one Interakt card |
| Duplicate queue scan | Legacy queue path called `systemHealthService->components()` (8 components) for `queue_worker` only |
| Duplicate Cashfree scan | `paidWithoutDeskOrderCount()` then full `reconcile()` when missing &gt; 0; `classifyFailedWebhooks()` / `assessLog()` N+1 `exists()` per failed log |
| Repeated snapshot / schema | `platformHealthSnapshot->current()` twice; `Schema::hasTable` per collector |
| Notification generation | Unchanged fingerprint gate already suppresses repeats; gate index writes tightened slightly |

### Architecture after

```
watchdog:send-critical-alerts
  → collectCriticalAlerts()
      cashfree: missingPaidOrderSample (one pass) + batched assess index
      queue/automation: shared Platform Health snapshot (memoized) or lean componentFor/card
      interakt: integrationHealthService->card('interakt') only
      site: in-process Kernel handle GET /up (no outbound HTTP / no retries)
      … bonvoice / radiumbox / error-spike COUNTs
  → WatchdogCriticalAlertGate::syncResolved + shouldNotify (fingerprint)
  → Telegram dispatch only when gate allows
```

### Benchmarks

| Metric | Before (prod) | After (local) |
|--------|---------------|---------------|
| Wall time | **21208 ms** | **~7–153 ms** (3-run avg **~56 ms**; budget test **&lt;3000 ms**) |
| Outbound HTTP for `/up` | Yes (timeout×retry) | **None** (`Http::assertNothingSent`) |
| Integration cards built | All 6 | **Interakt only** |
| System components built (legacy) | All 8 | **queue_worker only** |
| Cashfree missing-order passes | 1–2 (+ N+1 assess) | **1** + batched order/payment lookups |
| Alert behaviour | — | Same keys / thresholds / fingerprint suppress (existing suite green) |

### CPU reduction

| Scope | Before | After | Reduction |
|-------|-------:|------:|----------:|
| Command wall | 21208 ms | ~56 ms avg | **~99.7%** |
| Amortized cost units (/5m) | 4242 (~10% TOP20) | ~11 | **~99.7%** of this consumer |
| Host impact | Top-5 CPU cron | Negligible vs warmers/queue | Removes ~10pp from prior TOP20 share |

Production re-measure pending deploy (confirm &lt;3s on Hostinger under load).

### Files changed

| File | Change |
|------|--------|
| `app/Services/Operations/ProductionWatchdogService.php` | In-process `/up`; lean Interakt/queue paths; snapshot + schema memo; Cashfree sample |
| `app/Services/Operations/OperationsIntegrationHealthService.php` | `card($key)` single-card API |
| `app/Services/Operations/OperationsSystemHealthService.php` | `componentFor($key)` single-component API |
| `app/Services/Cashfree/CashfreePaymentIntegrityService.php` | `missingPaidOrderSample()`; batched assess index (no N+1 `exists`) |
| `app/ReadModels/Integrations/CashfreeIntegrityReadModel.php` | Delegate `missingPaidOrderSample()` |
| `app/Services/Operations/WatchdogCriticalAlertGate.php` | Batch forgets; skip redundant index rewrite |
| `tests/Feature/WatchdogCriticalAlertsPerformanceTest.php` | &lt;3s budget + no outbound HTTP |

### Regression (local)

`WatchdogCriticalAlertsPerformanceTest` + `ProductionWatchdogTest` + `IntelligentAutomationAlertSemanticsTest` + `CashfreeIntegrityReadModelTest`: **24 passed**.

---

## Phase 4 — Remaining polling optimization (implemented)

Performance-only. **`/dashboard/live` not modified.** UX preserved for active/visible tabs: Ably still delivers notifications instantly; HTTP becomes a slower safety net. Hidden tabs stop timers (not just skip fetch).

### What changed

| Area | Before | After |
|------|--------|-------|
| Notifications | `setInterval` 20s; skip fetch if hidden | Visibility-aware poller; **60s** while Ably/Reverb connected; **45s** balanced default; catch-up on visible |
| Team Activity | 30s; skip fetch if hidden (timer kept firing) | **60s**; **stop timer** when hidden; catch-up on visible |
| My Activity | 30s; skip fetch if hidden | **60s**; pause timer when hidden |
| OCC | 30s / full 120s; already paused when hidden | **45s / 180s** |
| C360 timeline / device | 30s / 10s; continued while hidden | **45s / 15s**; pause when hidden; email timeline refresh resets timer (dedupe) |
| Email thread | Hardcoded 20s; continued while hidden | **30s**; pause when hidden |
| Presence | Interval kept firing while hidden | Interval cleared while hidden; catch-up on visible |
| Duplicate / overlap | Independent intervals; notif HTTP + Ably both hot | Shared `visibility-aware-poller`; notification HTTP gated under Ably |

### Req/min summary (deliverable)

| | Req/min |
|--|--------:|
| **Current** (pre–Phase 4 model, Ably healthy, ~8 sessions) | **≈ 48–55** |
| **Optimized** (Phase 4, same scenario, tabs visible) | **≈ 26–32** |
| **Delta** | **≈ −40–45%** periodic RPS (live unchanged) |

### Files changed

| File | Change |
|------|--------|
| `resources/js/polling/visibility-aware-poller.js` | Shared pause-when-hidden single-flight poller |
| `resources/js/realtime-transport-status.js` | Shared Ably/Reverb connected flag for adaptive notification interval |
| `resources/js/live-notifications.js` | Visibility-aware + Ably-gated adaptive interval |
| `resources/js/live-dashboard-reverb.js` | Set/clear transport status on connect/teardown (no live HTTP changes) |
| `resources/js/dashboard-activity-refresh.js` | Visibility-aware 60s poller |
| `resources/js/dashboard-team-activity.js` | Stop timer when hidden; 60s default |
| `resources/js/customer-360-drawer.js` | Pause when hidden; email→timeline timer reset |
| `resources/js/service-case-email-workspace.js` | 30s + pause when hidden |
| `resources/js/presence-heartbeat.js` | Clear interval while hidden |
| `resources/js/operations-dashboard.js` | Default fallbacks 45s / 180s |
| `config/performance.php` | Balanced/low_resource + fallbacks |
| `config/system_settings.php` | Defaults / recommended intervals |
| `config/dashboard-activity.php` | 60s |
| `config/dashboard-team-activity.php` | 60s |
| `database/migrations/2026_08_07_123000_optimize_balanced_polling_intervals.php` | Bump prod settings that still match old balanced values |
| Blade hosts (navbar, C360, OCC, activity panels) | Dataset fallbacks |
| JS/PHP tests | Interval + hidden-tab coverage |

### Rollback notes

1. Revert Phase 4 JS/config commits.
2. Migration `down()` restores previous balanced numeric values when current value equals the new default.
3. `/dashboard/live` path was never touched — no live rollback needed.

### Regression (local)

- JS: `visibility-aware-poller`, `live-notifications`, `dashboard-activity-refresh`, `dashboard-team-activity` — **28/28 passed**
- PHP: `PerformanceRuntimeConfigTest`, `PerformanceSettingsServiceTest`, `PerformanceSystemSettingsTest` — **11/11 passed**

---

## Phase 5 — `queue:work` RadiumBox jobs (implemented)

Performance-only. Enrichment field application, Ready Queue / identity lifecycle, Cashfree onboarding path, and job retry backoff **unchanged**. Reduces redundant CPU/HTTP on the critical queue.

### Before (production remeasure, 2026-08-07)

| Metric | Value |
|--------|------:|
| Est. account CPU share | **~12%** (`queue:work`, #3 consumer) |
| Peak process CPU | **59%** at top-of-minute (`:02` UTC) |
| Wall (10s-capped `queue:work` test) | **2667 ms** |
| Jobs pending | **0** (completes; CPU is work cost, not backlog) |
| Recovery scan | **1316** orders / 15m → **11** recovered (**1315 ms**) |
| Daily enrichment starts (audit) | **~1652+** (`enrichment_started`) |
| Scheduler recoveries / day | **587+** |
| Job config | `tries=4`, backoff 60/300/1800s, queue **`critical`** |

Sources: [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md), [radiumbox-api-success-rate-degradation-investigation.md](./radiumbox-api-success-rate-degradation-investigation.md).

### Investigation findings

| Pattern | Finding |
|---------|---------|
| Duplicate jobs | No `ShouldBeUnique`; `dispatch()` always enqueued. Cashfree + Bonvoice + stale-pending recovery could pile N jobs per `orderId`. Auto-sync already gated on `Pending`. |
| Retry storms | `retryOrderEnrichment()` called `forget()` → reset `radiumbox_sync_attempts` to 0 every 15m → `max_recovery_attempts=10` never enforced. |
| Unnecessary sync | `process()` always wrote attempt/audit then called API layer; already-enriched orders still paid DB + lifecycle when HTTP was skipped inside `RadiumBoxService`. |
| Repeated API calls | Background path bypassed request cache; every job attempt = HTTP (5s timeout). Workspace path had request-scoped cache only. |
| Batch opportunities | Recovery/backfill already dispatch individual jobs (required for per-order lifecycle). Win is **dedupe + lookup TTL**, not multi-order API batch (API is per-orderid). |
| Queue starvation | Only `RadiumBoxOrderEnrichmentJob` uses `critical` (ahead of notifications/default/maintenance). Duplicate critical jobs delay notification drain within the 55s cron worker. |

### Architecture after

```
dispatch / dispatchIfNeeded
  → skip if Pending (unless force recovery)
  → markPending (idempotent touch if already Pending)
  → RadiumBoxOrderEnrichmentJob (ShouldBeUnique per orderId, uniqueFor=7200)

process(orderId)
  → if !needsEnrichment: markSynced (if needed) + lifecycle once; no HTTP / no attempt++
  → else: existing enrich → persist → lifecycle (identical outcomes)

radiumbox:recover-sync
  → retryOrderEnrichment WITHOUT forget()  // preserves attempt counters
  → force redispatch (unique lock drops true duplicates)

background lookup
  → Cache TTL 300s for non-retriable results only
```

### Benchmarks

| Metric | Before | After (local) |
|--------|--------|---------------|
| Duplicate `dispatch()` while Pending | **2+ jobs** | **1 job** (`RadiumBoxQueuePhase5PerformanceTest`) |
| Already-enriched `process()` | Attempt++ + audit + HTTP skip inside service | **0 HTTP**, attempts unchanged, **&lt;50 ms** |
| Same orderid lookup twice within TTL | **2 HTTP** | **1 HTTP** (300s cache) |
| Recovery redispatch attempt count | Reset to **0** via `forget()` | **Preserved** (max_recovery_attempts effective) |
| Bonvoice missed-call on complete order | Always `dispatch()` | `dispatchIfNeeded()` — no job |
| Enrichment success path fields / Ready Queue | — | **Identical** (no business-rule changes) |

### Expected production CPU effect (pending re-measure)

| Lever | Expected impact |
|-------|-----------------|
| Job uniqueness + Pending skip | Remove duplicate critical-queue HTTP (~2s avg API) |
| Attempt-preserving recovery | Stop unbounded 15m re-dispatch loops on permanently missing orders after 10 attempts |
| Already-enriched early exit | Cut wasted DB/audit on redundant jobs |
| Background lookup cache (5m) | Collapse recovery/duplicate bursts to one API call per orderid window |
| Critical-queue depth | Less starvation of `notifications` / `default` within `--max-time=55` |

Modeled: material cut of the **~12%** `queue:work` share and **59%** top-of-minute spike when duplicate/recovery storms were active. Confirm after deploy with the same SSH wall/CPU probes as [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md).

### Files changed

| File | Change |
|------|--------|
| `app/Jobs/RadiumBoxOrderEnrichmentJob.php` | `ShouldBeUnique` + `uniqueId()` / `uniqueFor=7200` |
| `app/Services/RadiumBox/RadiumBoxOrderEnrichmentService.php` | Pending skip; `dispatchIfNeeded`; early already-enriched exit; recovery without `forget()` |
| `app/Services/RadiumBox/RadiumBoxOrderEnrichmentSyncStore.php` | Idempotent `markPending` / `touchPending` |
| `app/Services/RadiumBox/RadiumBoxClient.php` | 300s background lookup cache (non-retriable only) |
| `app/Services/Bonvoice/BonvoiceMissedCallRecoveryService.php` | Use `dispatchIfNeeded` |
| `config/radiumbox.php` | `background_lookup_cache_seconds` (default 300; `0` disables) |
| `tests/Feature/RadiumBox/RadiumBoxQueuePhase5PerformanceTest.php` | Dedupe / skip / cache / attempt budgets |
| `tests/Feature/RadiumBox/RadiumBoxOrderEnrichmentJobTest.php` | Unique id + overwrite + attempt preserve |
| `tests/Feature/RadiumBox/RadiumBoxSyncRecoveryTest.php` | Stale pending preserves attempts |

### Rollback notes

1. Revert Phase 5 job/service/client/config commits.
2. Set `RADIUMBOX_BACKGROUND_LOOKUP_CACHE_SECONDS=0` to disable lookup cache only.
3. No migrations. In-flight unique locks expire after `uniqueFor` (7200s) or cache flush.

### Regression (local)

Focused RadiumBox job / recovery / auto-sync / Cashfree paid / Bonvoice enrichment dispatch: **32 passed**.

---

## Phase 6 — `automation:snapshot` (implemented)

Performance-only. Dashboard fields, validation categories, health counts, and Cashfree KPI meanings unchanged. Waiting-age / `waiting_over_*` still advance every minute via stub refresh when content is unchanged.

### Why it rebuilt every minute

| Factor | Detail |
|--------|--------|
| Scheduler | `automation:snapshot` → `everyMinute()` + `withoutOverlapping()` |
| Refresh API | Command always called `refresh()` → `builder->build()` (no content check) |
| Cache TTL | `automation.operations.snapshot` TTL = **60s** — expires as the next cron tick arrives, so the cache never helped the cron itself |

### Before (production, 2026-08-07)

| Metric | Value |
|--------|------:|
| Wall time | **6450 ms** (remeasure earlier: 6607 ms) |
| SQL queries | **28073** |
| Est. account CPU share | **~16%** (cost units 6607 in TOP20 model) |
| Top SQL | **25729×** `exists(orders.cashfree_payment_id = ?)` |

### Root causes

| Issue | Detail |
|-------|--------|
| Cashfree N+1 | `dashboardCounts()` → `classifyFailedWebhooks()` + `paidWithoutDeskOrderCount()` each called `assessLog()` with per-row `Order::exists()` — **~25.5k** unique payment IDs |
| Duplicate order scan | `ValidationCollector` re-queried all orders with active incidents via `whereHas` + cursor while `activeIncidents()` already loaded them |
| Sync store N+1 | `syncStore->status($orderId)` without preloaded `Order` → per-order SELECT + cache GET (~514 each) |
| Duplicate validation | `statusFor` + collector + repair-candidate paths re-ran serial validation for the same orders |
| Repair stats | Separate `COUNT(*)` + `MAX(created_at)` on `audit_logs` |
| No incremental path | Quiet minutes still paid full rebuild |

### Architecture after

```
automation:snapshot
  → contentFingerprint()          // cheap aggregates (incidents/orders/audits/outbox/cashfree)
  → if fingerprint unchanged:
        applyTimeDependentFields()  // waiting_over_* + age strings from stubs
        put cache (60s)             // incremental
  → else full build:
        activeIncidents()
        statusesFor()               // eligibility memo
        collectFromOrders(unique orders from incidents)  // no whereHas rescan
        Cashfree reliability snapshot (batched assess + 120s cache)
        repair statistics (single aggregate query)
        store snapshot + incident stubs
```

### Benchmarks

| Metric | Before (prod) | After |
|--------|---------------|-------|
| Wall time (full) | **6450 ms** | Local budget &lt;400 queries; Cashfree payment `exists` **&lt;20** (`AutomationSnapshotPerformanceTest`, 120 cases + 40 failed webhooks) |
| Wall time (incremental / unchanged) | same full cost every minute | **&lt;40 SQL** local; command reports `incremental` |
| Cashfree payment exists checks | **25729** | Batched `whereIn` chunks (prod probe: batched classify **23 ms / 3 SQL** for 163 failed logs; batched paid scan **~2.1s / 105 SQL** for 25.5k payments vs prior exists storm) |
| Cashfree reliability reuse | none | **120s** snapshot cache shared with other callers |
| Quiet-minute rebuild | always | **Skipped** when fingerprint matches (time fields still updated) |

Production remeasure ([p0-production-remeasure-after-optimizations.md](./p0-production-remeasure-after-optimizations.md)): fingerprint **rarely hit** under live traffic (outbox + `orders.updated_at` churn) → still **11–15s full-rebuild** when the cron ran. Superseded by **Phase 8** event-driven dirty flags.

### Rollback notes (Phase 6)

1. Revert Phase 6 service/command/test changes.
2. No migrations. Cache keys `automation.operations.snapshot(.meta)` and `cashfree:webhook:reliability:dashboard_snapshot` expire naturally.

---

## Phase 8 — `automation:snapshot` event-driven infrastructure (implemented)

Performance-only. **No UI / business-logic / schema changes.** Dashboard DTO, routes, and KPI meanings unchanged. Phase 1 of the redesign: infrastructure + schedule; specialized per-metric mutators beyond Cashfree/RecentEvents come later.

### Root cause (why Phase 6 still full-rebuilt in production)

| Factor | Detail |
|--------|--------|
| Monolithic fingerprint | Single hash over incidents + orders + audits + **outbox pending/failed** + Cashfree counters |
| Outbox churn | `outbox:process` every minute moves pending/failed → fingerprint miss every tick |
| Enrichment churn | RadiumBox `persist()` bumps `orders.updated_at` / sync columns on active cases |
| Assignment / grace | Concurrent writes move `incidents.max_updated_at` and automation audit `MAX(id)` |
| Fingerprint gaps | Duplicate-serial set, paid-without-order, completed-today outbox not fully covered — but volatility alone was enough to miss |
| Stuck mutex (ops) | Remeasure also found `withoutOverlapping` stuck since ~05:55 — separate defect; Phase 8 uses short overlap TTLs (5 / 20 min) |

Quiet local tests hit incremental; live Hostinger did not.

### Event dependency map (dirty slices)

| Slice | Snapshot fields | Write hubs that mark dirty |
|-------|-----------------|----------------------------|
| `Health` | Automation health counts, waiting/unassigned/grace (time fields from stubs) | Assignment, status change/close, grace begin, automation monitor events, Cashfree order create, RadiumBox fail, repair |
| `Validation` | validationBy*, duplicateSerialConflicts, radiumBoxNotFoundQueue | Same case/order hubs + repair |
| `RecentEvents` | recentAutomationEvents feed | Monitor audits, assignment, grace, repair |
| `Cashfree` | cashfree_* keys in healthCounts | `CashfreeWebhookReliabilityMetrics::recordOrderCreated` (+ soft-merged every light tick) |
| `Repair` | repairStatistics | `OrderIdentityRepairService` |
| `All` | everything | `--reconcile` / forced full rebuild |

Attendance/presence do **not** write snapshot fields directly (only via reassignment → assignment hub).

### New architecture

```
Write hubs
  → AutomationOperationsSnapshotInvalidator::markDirty(slice…)
       (cache key automation.operations.snapshot.dirty — no schema)

Every minute:  automation:snapshot          (light tick)
  → if no cache OR dirty Health/Validation/Repair/All OR dirty age ≥120s
        full rebuild, clear dirty
  → else
        merge Cashfree KPIs (120s sub-cache)
        rebuild RecentEvents if dirty
        applyTimeDependentFields(stubs)
        put cache TTL 900s

Every 15 min:  automation:snapshot --reconcile
  → force full rebuild (missed-event safety net)
  → log warning if fingerprint changed while dirty was empty
```

`get()` serves cache + time fields; if dirty, runs `refreshDetailed()` so the Pipeline UI never waits a full 15 minutes after a marked write.

### Benchmarks

| Path | Before (prod remasure / Phase 6) | After (Phase 8 local) |
|------|----------------------------------:|----------------------:|
| Quiet minute | **11–15s full rebuild** (fingerprint miss) | **`incremental`**, &lt;80 SQL (`AutomationSnapshotPhase8InfrastructureTest`) |
| Dirty Health/Validation | same full cost | **1 full rebuild** then clear flags (coalesced) |
| Reconcile | every minute | **every 15 minutes** only |
| Full rebuild SQL budget | 28073 → ~193 prod / &lt;400 local (Phase 6) | unchanged builder; fewer invocations |
| Amortized cron CPU | ~16% TOP20 every minute | Quiet minutes ≈ Cashfree merge + stubs; full pass only on events or */15 |

### Files changed

| File | Change |
|------|--------|
| `app/Enums/AutomationSnapshotSlice.php` | Dirty-slice enum |
| `app/Services/Automation/AutomationOperationsSnapshotInvalidator.php` | Cache-backed dirty flags |
| `app/Services/Automation/AutomationOperationsIncrementalUpdater.php` | Light-slice merge + full-rebuild gate |
| `app/Services/AutomationOperationsSnapshotService.php` | Event-driven refresh; TTL 900s; reconcile API |
| `app/Services/AutomationOperationsSnapshotBuilder.php` | `recentAutomationEvents()` public for light slice |
| `app/Console/Commands/AutomationSnapshotCommand.php` | `--reconcile`; mode reporting |
| `bootstrap/app.php` | everyMinute light + everyFifteenMinutes `--reconcile`; short overlap TTLs; background |
| Write hubs | Monitor, grace, assignment, status, Cashfree reliability, RadiumBox fail, repair → `markDirty` |
| `tests/Feature/AutomationSnapshotPhase8InfrastructureTest.php` | Quiet / dirty / reconcile / coalesce |
| `tests/Feature/SchedulerHardeningTest.php` | Light + reconcile schedule assertions |

### Risks

| Risk | Mitigation |
|------|------------|
| Missed dirty mark → stale KPIs | 15m `--reconcile`; dirty-age ≥120s forces full rebuild; reconcile logs `missed_invalidation` |
| HTTP `get()` full rebuild under dirty | Admin Pipeline only; coalesced dirty; prefer light cron drain |
| Stuck schedule mutex | Overlap TTL 5m (light) / 20m (reconcile) vs prior 24h default |
| Cashfree soft-merge every minute | Uses existing 120s sub-cache — cheap when warm |

### Rollback notes

1. Revert Phase 8 service/enum/command/schedule/hub wiring + tests.
2. No migrations. Dirty key `automation.operations.snapshot.dirty` expires (1h TTL) or `Cache::forget`.
3. Restoring every-minute full rebuild is sufficient emergency rollback.

### Regression (local)

`AutomationSnapshotPhase8InfrastructureTest` + `AutomationSnapshotPerformanceTest` + automation dashboard cache/command + schedule light/reconcile: **9 passed**.

---

## Phase 7 — `platform:snapshots:warm` (implemented)

Performance-only. No UI, business-rule, or schema changes. Snapshot payloads unchanged when a warmer runs; incremental path skips rebuild while TTL-fresh caches remain valid.

**Canvas:** [`p0-platform-snapshots-warm-optimization.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-platform-snapshots-warm-optimization.canvas.tsx)

### Root cause

Production remasure ranked this cron **#1 (~28% account CPU)** at **~11.6s wall / 72–88% process CPU every minute**. The warmer rebuilt all 10 Priority zones every 60s even though P1 TTL is **120s** and P3 TTL is **300s**, and duplicated expensive work inside each cycle:

| Duplicate | Occurrences per warm |
|-----------|---------------------:|
| Platform health full probe | 2× |
| Executive KPI context (7 SQL) | **8×** (one `force:true` per card) |
| Overall health compute+store | 2× |
| Queue `jobs`/`failed_jobs` capture | 3–4× |
| Scheduler probe | 2× |

### Before / after (local)

| Run | Before wall | After wall | Before SQL | After SQL |
|-----|------------:|-----------:|-----------:|----------:|
| Cold `warmAll` | **205 ms** | **125 ms** (−39%) | **331** | **245** (−26%) |
| 2nd warm (caches fresh) | **66 ms** | **8 ms** (−88%) | **267** | **24** (−91%) |
| Executive zone alone | — | — | **79** (8× context) | **16** (1× context) |

Local DB is sparse (email/integration cheap); production wall is dominated by the same duplicate patterns on large tables. Relative gains scale up on Hostinger.

### Amortized CPU (production estimate)

Before: **11613 ms every minute**.

After (deduped cold + skip-when-fresh):

| Minute | Behavior | Est. wall |
|-------:|----------|----------:|
| 0 | Full cold (deduped) | ~5.0 s |
| 1 | All fresh → skip | ~0.2 s |
| 2 | P1 only (120s TTL) | ~2.0 s |
| 3 | Skip | ~0.2 s |
| 4 | P1 only | ~2.0 s |
| 5 | P1 + P3 (300s TTL) | ~5.0 s |

**5-minute average ≈ 1.9 s/min → ~84% CPU reduction** for this command (target ≥70%). Production confirmation pending deploy.

### SQL reduction drivers

| Driver | Change | Effect |
|--------|--------|--------|
| Executive 8× stampede | `ExecutiveMetricsService` in-request force memo | 8 context builds → **1** |
| Double platform health | Warmer no longer pre-probes before zone refresh | Probe **1×** / warm |
| Double overall health | Critical-alerts warmer defers to zone | Compute **1×** |
| Queue capture fan-out | Scoped `QueueMetricsService` + capture memo | **1** capture / command |
| Automation re-probe | Scheduler item reads platform health snapshot | No 2nd queue/scheduler probe |
| Over-warming | Skip warmer when zone + overview still fresh | Honors 120s/300s TTL |

### Files changed

| File | Change |
|------|--------|
| `app/Services/Executive/ExecutiveMetricsService.php` | Force bypasses Redis only; in-request memo after first force |
| `app/Services/Platform/Warmers/PlatformHealthSnapshotWarmer.php` | Single zone refresh (no double probe) |
| `app/Services/Platform/Warmers/CriticalAlertsSnapshotWarmer.php` | Remove duplicate overall-health pre-compute |
| `app/Services/Platform/Warmers/PlatformSnapshotWarmingService.php` | Skip-when-fresh; return `skipped[]` |
| `app/Console/Commands/WarmPlatformSnapshotsCommand.php` | Report skipped warmers |
| `app/Infrastructure/Queue/QueueMetricsService.php` | Request/command capture memo |
| `app/Services/Platform/Health/PlatformHealthSnapshotService.php` | Request/command probe memo |
| `app/Services/Platform/PlatformAutomationOverviewService.php` | Reuse health snapshot for scheduler/queue item |
| `app/Providers/InfrastructureServiceProvider.php` | Scoped `QueueMetricsService` |
| `app/Providers/PlatformDashboardServiceProvider.php` | Scoped `PlatformHealthSnapshotService` |
| `tests/Feature/Platform/PlatformSnapshotWarmPerformanceTest.php` | Cold query budget + incremental skip |
| `tests/Unit/Executive/ExecutiveMetricsForceMemoTest.php` | 8× force → 1 context assert |

### Remaining bottlenecks

| Item | Why it remains |
|------|----------------|
| **Cold email_operations / integration_health** | Still many COUNT/live probes when TTL expires |
| **`CACHE_STORE=database`** | Warm path Cache::put/get is SQL; Redis would cut further |
| **Production re-benchmark** | Confirm wall + `%CPU` after deploy |

### Regression (local)

`PlatformSnapshotWarmPerformanceTest` + `ExecutiveMetricsForceMemoTest` + platform hardening / health unification / queue metrics: **26 passed**.

---

## Sources

- Production SSH probes 2026-08-07 (tinker HttpKernel timing, query log grouping, Interakt counts, automation:snapshot 6450ms/28073 SQL)
- Production remasure: [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md) (`platform:snapshots:warm` = 11613 ms)
- Local Phase 1 warm budget: `tests/Feature/DashboardLivePhase1PerformanceTest.php`
- Local Phase 2 webhook budget: `tests/Feature/InteraktWebhookEnqueueOnlyPerformanceTest.php`
- Local Phase 3 watchdog budget: `tests/Feature/WatchdogCriticalAlertsPerformanceTest.php`
- Local Phase 4 JS pollers: `tests/js/visibility-aware-poller.test.js`, `live-notifications.test.js`
- Local Phase 5 RadiumBox queue budget: `tests/Feature/RadiumBox/RadiumBoxQueuePhase5PerformanceTest.php`
- Local Phase 6 automation snapshot budget: `tests/Feature/AutomationSnapshotPerformanceTest.php`
- Local Phase 7 platform warm budget: `tests/Feature/Platform/PlatformSnapshotWarmPerformanceTest.php`
- `app/Http/Controllers/DashboardLiveController.php`
- `app/Services/DashboardService.php`, `OperatorDashboardCache.php`
- `app/Models/Incident.php` (`slaStatus` → `SettingService`)
- `app/Services/SettingService.php` (`app.settings.all`)
- `app/Http/Controllers/Webhooks/InteraktWebhookController.php`
- `app/Services/Outbox/OutboxProcessorService.php`
- `app/Services/Operations/ProductionWatchdogService.php`
- `app/Services/Platform/Warmers/PlatformSnapshotWarmingService.php`
- `app/Jobs/RadiumBoxOrderEnrichmentJob.php`, `app/Services/RadiumBox/RadiumBoxOrderEnrichmentService.php`
- `resources/js/live-dashboard-polling.js`, `live-notifications.js`, `polling/visibility-aware-poller.js`
- Related: [radium-desk-performance-audit.md](./radium-desk-performance-audit.md), [periodic-polling-endpoints-investigation.md](./periodic-polling-endpoints-investigation.md), [p0-cpu-incident-remeasure.md](./p0-cpu-incident-remeasure.md), [radiumbox-api-success-rate-degradation-investigation.md](./radiumbox-api-success-rate-degradation-investigation.md)
