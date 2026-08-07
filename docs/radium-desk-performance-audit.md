# Radium Desk — Production Performance Audit

**Date:** 2026-08-05  
**Scope:** Read-only investigation. No code changes. No optimizations applied.  
**Method:** Static analysis of controllers, services, views, migrations, scheduler, Vite build artifacts, and existing performance tests/budgets.  
**Single source of truth for this investigation.**

---

## Executive verdict

Radium Desk’s main operator path is slower than the stated targets primarily because:

1. **Dashboard loads the entire active-incident population into PHP memory** on every request (and every live poll), then classifies/filters/sorts in memory.
2. **Team Activity builds a multi-query roster panel on every dashboard first paint**, even when collapsed.
3. **Email Intake KPI re-scans all Needs Review / Failed messages in PHP** (and can write priority-detection audit rows) on every dashboard load and KPI poll — with **no cache**.
4. **Customer360 pays a heavy drawer open + duplicate Case Intelligence / timeline merges** across separate AJAX requests, with request-scoped memoization only.
5. **Global search uses leading-wildcard `LIKE` / `EXISTS` chains** that cannot use existing B-tree indexes efficiently.
6. **Default cache store is `database`** — Platform code itself documents this as unsuitable for production zone snapshots.

Measured / budgeted evidence (test + build artifacts; production wall-clock will be higher with real data volume):

| Surface | Evidence | Target |
|---------|----------|--------|
| Customer360 initial drawer | Unit budget `<500ms` (`Customer360ServiceTest`) | `<400ms` |
| Team Activity panel | ≤120 queries for 3 agents (test ceiling; production roster larger) | `<250ms` |
| Operations CC first paint | HTML `<120KB`; warm section cache = **0 queries** | Admin `<700ms` |
| Dashboard snapshot | **1** active-incident SELECT per request (good); no cross-request cache | Dashboard `<600ms` |
| Frontend CSS | Built `app-*.css` = **634 KB** | Perceived load |
| Frontend dashboard JS | Built `dashboard-*.js` = **187 KB** | Perceived load |

**Gmail API is not called during dashboard page load or `/dashboard/live`.** Intake KPIs and Needs Attention use DB only. Gmail runs on scheduled sync and lazy content open.

---

## Targets vs current architecture

| Surface | Target | Current posture | Gap drivers |
|---------|--------|-----------------|-------------|
| Dashboard | `<600ms` | Full active-incident hydrate + KPI aggregates + Team Activity + Email Intake scan + Blade | Memory scale with open cases; uncached polls |
| Customer360 | `<400ms` | Initial drawer under test budget 500ms; Overview then fires IRA AJAX; Timeline/AI rebuild intelligence | Duplicate intelligence; action-status N+1; Bonvoice full load |
| Service Case Search | `<300ms` | In-panel = in-memory rescan; global = unindexed LIKE | Leading wildcards; double collection scan for count |
| Team Activity | `<250ms` | Built on every dashboard SSR; ≤120 SQL for tiny roster | Fan-out services; full HTML refresh every 30s when expanded |
| Administration | `<700ms` | Admin home is light; Ops CC / Audit / System Settings heavier | Audit distinct scans; Ops live polls; Platform needs Redis |

---

## Impact-ranked findings

Legend: **Gain** / **Risk** / **Effort** = Low · Medium · High

| Rank | Finding | Surfaces | Gain | Risk | Effort |
|------|---------|----------|------|------|--------|
| 1 | Active-incident full-table hydrate every request/poll | Dashboard, Search (in-panel), live refresh | High | Medium | Major |
| 2 | Email Intake `aggregateCounts()` full PHP scan + possible audit writes on KPI path | Dashboard, Email Intake hover data | High | Low–Med | Quick / Medium |
| 3 | Team Activity full `build()` on SSR even when collapsed | Dashboard, Team Activity | High | Low | Quick |
| 4 | No cross-request cache for operator KPI / case list scalars | Dashboard | High | Medium | Medium |
| 5 | CaseIntelligenceEngine rebuilt per AJAX (Overview IRA + AI tab) | Customer360 | High | Medium | Medium |
| 6 | Timeline merges ~14 sources with no SQL limit; poll refreshes full HTML | Customer360 | High | Medium | Medium |
| 7 | Communication action status ~3 audit lookups × N actions on every drawer open | Customer360 | Medium | Low | Quick |
| 8 | Global search leading-wildcard LIKE / EXISTS / remarks body | Search | High | Medium | Medium–Major |
| 9 | `CACHE_STORE` default `database`; Platform requires Redis | Admin Platform, Ops caches | High | Medium | Quick (ops) |
| 10 | Live poll re-renders full KPI strip HTML + case rows every 15–60s | Dashboard, API | Medium | Low | Medium |
| 11 | `Order::count()`, superadmin `User`/`AuditLog` counts on dashboard | Dashboard | Medium | Low | Quick |
| 12 | Email thread loads entire conversation; outbound selects `body_html` | Customer360 Email | Medium | Low | Quick |
| 13 | Bonvoice loads all call events for phone into PHP | Customer360 Overview/Timeline | Medium | Low | Medium |
| 14 | Duplicate drawer work (action visibility, serial states, overflow) | Customer360 | Low–Med | Low | Quick |
| 15 | Audit log index: distinct user_id + events on every page | Administration | Medium | Low | Quick |
| 16 | Monolithic 634 KB CSS + 187 KB dashboard JS | Frontend / all | Medium | Low | Medium |
| 17 | Email Intake KPI hover shows only divider | UX | — (correctness) | Low | Quick |
| 18 | Queue via DB + scheduler drain (no Horizon); notifications often sync | Background / perceived | Medium | Medium | Major |
| 19 | Executive / Ops / Dashboard KPI SQL not unified | Duplicate work | Medium | Medium | Medium |
| 20 | Orphan SLA/Ops Health C360 caches unused; orphan dashboard partials | Dead weight | Low | Low | Quick |

---

## 1. Dashboard

### Architecture

Three surfaces:

| Surface | Route | Sharing model |
|---------|-------|---------------|
| Operator dashboard | `GET /dashboard` | Request-scoped `DashboardSnapshotStore` only |
| Operations Control Center | `GET /admin/operations` | 30s section cache |
| Platform Mission Control | `GET /admin/platform` | Zone snapshots 120–300s (Redis recommended) |

No Livewire on dashboard paths. Updates use Blade SSR + JSON polling / Reverb.

### Widget inventory (operator `/dashboard`)

| Widget | Data source | Cache | Notes |
|--------|-------------|-------|-------|
| KPI strip | `DashboardService::statsFor()` | None (cross-request) | Mix of snapshot KPIs + separate SQL |
| Agent action cards | `DashboardKpiAggregator::supportAgentKpis()` | Request memo | Uses snapshot appointments |
| Email Intake KPI | `IncomingEmailIntakeCounterService::dashboardWidget()` | **None** | Full Needs Review scan |
| Online users | `sessions` + `users` | None | 5-minute activity window |
| Service cases panel | `recentServiceCases()` over snapshot | None | Page size 35; load-more 25 |
| Team Activity | `TeamActivityPanelService::build()` | None | **Always built on SSR if permitted** |
| Embedded workspaces | Operations workspace panel | — | Optional embed path |
| Customer360 drawer host | Lazy AJAX | — | Not on first paint |
| Incoming call host | Reverb | — | Realtime only |

Orphan / unwired partials (maintenance noise, not runtime cost): `admin-metrics-strip`, `automation-health-card`, `sla-alert-cards`, `action-stats`.

### Measured behaviors

| Check | Result | Source |
|-------|--------|--------|
| Active incident SELECT per request | **1** (even after 3 `get()` calls) | `OperationsDashboardPerformanceTest` |
| Queue classification per incident | Once per request (memoized) | Same + classifier tests |
| Cross-request KPI/case cache | **Absent** | `DashboardSnapshotStore` is request-scoped |
| Live poll interval | 30s balanced (15s high / 60s low) | `config/performance.php` |
| Team Activity poll | 30s, only when panel expanded | `dashboard-team-activity.js` |

### Per-request cost model (operator dashboard)

```
DashboardController::index
├─ serviceCaseFilterCounts()     → DashboardSnapshot::get()  [1 heavy Incident+eager query]
├─ recentServiceCases()          → same snapshot
├─ statsFor()
│  ├─ snapshot KPIs / SLA        → same snapshot
│  ├─ Order::count()             → full-table COUNT
│  ├─ onlineUsers()              → sessions subquery
│  ├─ incidentStatusCounts()     → GROUP BY status
│  ├─ refund/approval aggregates → optional role gates
│  ├─ email_intake_widget        → ALL needs-review rows into PHP + categorize
│  └─ superadmin User/AuditLog counts
└─ teamActivityPanelService->build()  → roster + audits + presence + calls + pending + badges
```

**Repeated calculations within request:** mitigated for incident load and queue classification.  
**Repeated across polls:** entire tree above re-runs every live refresh (new HTTP request → new snapshot).

### Duplicate data across widgets

| Data | Consumers | Shared today? |
|------|-----------|---------------|
| Active incidents / queues / SLA | KPI, filters, case list | Yes — `DashboardSnapshotStore` |
| Email intake counts | KPI strip (+ agent toolbar card) | Same call in `statsFor` |
| Online users | KPI + live payload | Same `statsFor` |
| Open / waiting / overdue | Operator vs Platform Operations Snapshot | **No** — separate SQL (`ExecutiveMetricsContextBuilder` vs snapshot) |
| Cashfree / RadiumBox health | Ops CC vs Platform Integration | Separate caches (30s vs 120s) |

### Consolidation recommendations (no implementation)

1. **Shared short-TTL “operator dashboard snapshot”** (scalars + optional slim case list) similar to Ops `operations:dashboard:sections:*` (30s).
2. **Split poll payload:** fast-changing case rows vs slow-changing admin counts (`Order::count`, users, audit totals).
3. **Do not build Team Activity on SSR when collapsed** — return shell + lazy `GET /dashboard/team-activity`.
4. **Precompute Email Intake attention categories** into columns or a 30–60s cache; never call `matchAndAudit` from read path.
5. **Unify executive KPI read model** with `CaseQueueReadModel` / shared active-case projection.

### Expected impact if consolidated

| Change | Dashboard improvement | Risk |
|--------|----------------------|------|
| Defer Team Activity | Large first-paint drop for supervisors | Low |
| Cache Email Intake widget 30–60s | Removes PHP scan from hot path | Low |
| Cross-request snapshot 15–30s | Cuts poll DB/CPU dramatically | Medium (staleness) |
| Slim eager loads / SQL pagination for cases | Scales with open-case growth | Medium |

---

## 2. Customer360

### Actual UI model (important)

Customer360 is **not seven tabs**. It is a drawer SPA:

| Label | Key | Load |
|-------|-----|------|
| Overview | `overview` | Initial HTML + **lazy** IRA executive summary |
| Timeline | `timeline` | Lazy AJAX |
| IRA AI | `ai-assistant` | Lazy AJAX |

**Email** = modal workspace (lazy). **Audit** = AI workbench POST events only. **Attachments** = email/refund flows, not a C360 tab. **Performance Intelligence** = separate Super Admin product (`/admin/performance-intelligence`), not C360.

Profiler logs: `customer360.drawer.open`, `.executive_summary`, `.timeline_tab`, `.ai_tab`.

### Overview (initial drawer)

**Hot spots on every open** (`Customer360Service::buildDrawerData` / `drawerData`):

| Work | Pattern | Risk |
|------|---------|------|
| Customer summary | 4 COUNT queries via `CustomerScopeQueryCache` (new instance, request-only) | Medium |
| Recent communication | Order pluck + WhatsApp + **full email dispatch audit history** | High |
| Bonvoice intelligence | **All** `BonvoiceCallEvent` rows for phone → PHP group | High |
| Communication action statuses | ~3 audit/lifecycle queries × ~7 actions | High N+1 |
| Action visibility | Called twice in happy path; again in overflow menu | Duplicate |
| Serial request states | Computed twice (payload + overflow) | Duplicate |
| Communication section | WhatsApp + email headers + Interakt | Medium |
| Modals in initial HTML | Email + WhatsApp panels always included | Payload |

**Budget:** initial `drawer_data` + Blade render must finish `<500ms` in unit test (sparse SQLite). Production target `<400ms` is tight once Bonvoice/audit history grows.

### Timeline

- Registry: **14** timeline source factories in `AppServiceProvider`.
- `Customer360TimelineService` merges sources **without SQL limit**, then business composer classifies/clusters in memory and paginates (page size 8).
- Request cache: `Customer360TimelineRequestCache` — **same HTTP request only**.
- Poll: every **30s** (balanced) while timeline was loaded — refreshes with `offset=0` (full recompose), not delta.

### IRA / Performance Intelligence (in-drawer)

- Overview IRA = `CaseIntelligenceEngine::build()` via `GET .../executive-summary`.
- IRA AI tab = **second** full build via `GET .../ai-workbench`.
- Engine memoizes in `$snapshotCache` **per request only** — separate AJAX requests do not share.
- Fact collector pulls **full timeline merge** + AI bundle builders.

Performance Intelligence admin snapshots (`performance_intelligence_snapshots`) are **daily scheduled**, not part of C360 drawer.

### Email (modal)

| Issue | Detail |
|-------|--------|
| Full thread load | `inboundMessages()` / `outboundMessages()` → `->get()` no SQL LIMIT; paginate in PHP |
| Over-fetch | Outbound selects `body_text`, `body_html` while UI uses preview |
| Poll | Email workspace JS ~20s |
| Gmail API | Only on content open / sync — **not** drawer open |

### Audit / Attachments

- Workbench audit POST is light.
- No dedicated attachments tab performance path beyond email content lazy load.

### Duplicate / N+1 / lazy opportunities

**Already lazy (good):** executive summary, timeline tab, AI tab, email thread, device refresh.

**Should lazy / share (recommended):**

1. Redis/file cache for Case Intelligence keyed by `incident_id + updated_at` shared by executive-summary + ai-workbench.
2. Defer communication action statuses until actions section visible.
3. Defer Bonvoice full history to timeline or expand interaction.
4. SQL-limit timeline sources; poll deltas or ETag.
5. Email thread: SQL cursor pagination; drop body columns from list query.
6. Collapse duplicate `actionVisibility` / serial state calls.

### Heavy Blade

Every sub-endpoint returns **server-rendered HTML strings** (`view()->render()`), including large IRA/AI grids and timeline clusters. This increases TTFB vs JSON + client render, but matches current architecture.

---

## 3. Service Case Search

### Paths

| Path | Mechanism | Pagination |
|------|-----------|------------|
| In-panel quick filter | PHP filter over `DashboardSnapshot` | In-memory slice; page 35 / more 25 |
| Global navbar search | `UniversalSearchService` SQL LIKE/EXISTS | Hard cap **20**; then `search-rows` HTML |
| Intake fallback | Exact `order_id` / phone / serial | Indexed lookups |
| Incidents listing | `IncidentListingQuery` | SQL paginate 15; order_id `LIKE %…%` |

### Latency drivers

1. **In-panel search** reloads snapshot semantics (same request store) but **re-scans full filtered collection** for `matchingServiceCaseCount` — double work. Client debounce 250ms still hits server per query change.
2. **Global search** per token:

```sql
reference_no LIKE '%token%'
OR order fields LIKE '%token%'   -- phone, order_id, serial, txn, email, name, device…
OR EXISTS device_models.name LIKE
OR remarks.body LIKE             -- no body index
OR close_exceptions.exception_id LIKE
OR InteraktMessage subquery LIKE
```

Leading `%` → **index cannot be used** for match; `orderByRaw` CASE ranking adds more EXISTS. Expect filesort / temporary tables on large `orders` / `remarks` / `interakt_messages`.

3. **Serial / customer / order lookup** for intake is the healthy path (exact / `whereIn` phones). Prefer routing operators there when query looks like order id / phone / serial.

### Indexes present vs gaps

**Present (helpful for exact / status filters):**  
`orders.order_id` UNIQUE, `serial_number`, `transaction_id`, `customer_email`, `customer_phone`, `customer_name`; `incidents.status`, composites `(status, assigned_to_user_id)`, `(status, created_at)`, `reference_no` UNIQUE; email message queue indexes.

**Gaps for search:**

- No FULLTEXT / trigram on search columns
- `remarks.body` unindexed
- `reference_no LIKE '%x%'` cannot use UNIQUE index
- WhatsApp search via unindexed-friendly LIKE on message fields

### Ranked worst SQL (search + dashboard)

| Rank | SQL pattern | Why bad |
|------|-------------|---------|
| 1 | Universal search OR chain of `%LIKE%` + EXISTS + remarks | Full scans / nested loops |
| 2 | Dashboard `Incident::with(...)->whereIn(status)->get()` | Grows with all open cases |
| 3 | Email Intake load all NeedsReview/Failed then PHP categorize | Memory + CPU; may write audits |
| 4 | `Order::count()` on every dashboard | Full table aggregate |
| 5 | Team Activity audit/presence fan-out | Many queries; test allows ≤120 for 3 users |
| 6 | Communication action status audits × actions | N+1 on C360 open |
| 7 | Bonvoice all events for phone | Unbounded collection |
| 8 | Email thread full `get()` + body_html | Memory / IO |
| 9 | Audit log index `distinct pluck(user_id)` + distinct events | Scales with audit_logs size |
| 10 | Superadmin `AuditLog::count()` on dashboard | Full table COUNT |

---

## 4. Database

### Large / growing tables (by usage pattern)

| Table | Growth driver | Hot access |
|-------|---------------|------------|
| `incidents` | Cases | Dashboard snapshot `whereIn(status)` |
| `orders` | Commerce | Search LIKE; `Order::count` |
| `audit_logs` | Everything | Team Activity, C360 actions, admin audit, counts |
| `incoming_email_messages` | Intake | Needs attention scan |
| `remarks` | Notes | Search body LIKE |
| `interakt_messages` | WhatsApp | Search subquery |
| `bonvoice_call_events` | IVR | C360 / Team Activity calls |
| `jobs` / `failed_jobs` | Queue | Ops metrics |
| `sessions` | Auth | Online users |
| `performance_intelligence_snapshots` | Daily job | Team badges (read-only) |

### Anti-patterns observed

- **COUNT(\*)** on large tables in request path (`orders`, `users`, `audit_logs`).
- **Repeated aggregates** across Platform executive cards vs operator KPIs (same conceptual metrics, different builders).
- **Filesort / temp tables** expected on universal search `ORDER BY CASE … LIKE` without covering indexes.
- **Full table / large range scans** for `%term%` and unfiltered collection loads (email attention, Bonvoice, email threads).
- **In-memory joins** substituting for SQL (dashboard queues, business timeline clustering).

### Explain-plan guidance (for production DBA — not run in this audit)

Run on production replica for:

1. Universal search query with 1–2 tokens against live data.
2. `Incident` active status + typical eager-load plan.
3. `incoming_email_messages` `WHERE status IN (NeedsReview, Failed)`.
4. `audit_logs` latest-by-user Team Activity queries.
5. `Order::count()` vs approximate / counter table.

Capture: `type`, `key`, `rows`, `Extra` (`Using filesort`, `Using temporary`, `Using where`).

---

## 5. Cache

**P0 investigation (2026-08-07):** database cache remains a major hidden MySQL/CPU contributor after Phases 1–8. Investigation only — no implementation.  
**Canvas:** [`p0-laravel-cache-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-laravel-cache-investigation.canvas.tsx)  
**Companion:** [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) § Laravel Cache.

### Current architecture

| Setting | Production / default | Effect |
|---------|----------------------|--------|
| `CACHE_STORE` | **`database`** (confirmed 2026-08-07 SSH; `.env.example` default) | Every `Cache::` op = SQL on `cache` / `cache_locks` |
| Table | `cache` (`key` PK, `value` mediumText, `expiration` index) + `cache_locks` | Large zone/HTML blobs amplify serialize CPU |
| Prefix | `CACHE_PREFIX` / `{app}-cache-` | All keys prefixed |
| `serializable_classes` | `false` | Eloquent graphs must not be stored (snapshot v2 is array-encoded) |
| Policy | `PlatformCachePolicy` | Documents Redis as required for Platform ECC |
| Call sites | **68** files under `app/` use `Cache::` | No `cache()` helper usage |

### Reads / writes by surface

| Surface | Hot keys | TTL | Est. cache ops | Notes |
|---------|----------|-----|----------------|-------|
| Dashboard / live | `operator.dashboard.snapshot:v2`, `slow_scalars:v1`, `incoming_email:dashboard_widget:*`, `app.settings.all` | 15–45s / forever | **3–8 / request** | Post–Phase 1: settings memo → ≤1 SQL for settings; warm live ~7 SQL total |
| Assign reference | Snapshot forget + automation dirty (via coalescer) | — | **1–3 / batch** | No direct Cache reads; invalidation only |
| Platform warmers | `platform:zone:*:snapshot`, overviews, `platform:warm:lock:*` | 120–300s / 55s lock | **20–200+ / min** | #1 cache SQL source every minute |
| Automation snapshot | `automation.operations.snapshot[+meta/dirty]`, Cashfree reliability | 900s / dirty / 120s | **10–40 / min** | Incremental path still pays cache SQL |
| Notifications | `operator_alert:dispatch:*` on send | 6h | ~0–1 | Poll path negligible |
| Watchdog | fingerprints + `watchdog:uptime:{date}` | 2d | 10–30 / 5m run | Amortized low |
| Gmail sync | token + `gmail.sync.metrics.*` increments | EOD | **10–30+ / min** | has+put+increment amplification |
| Heartbeat / presence | `operations:scheduler:last_run_at`, presence last_* | 3600s | **8–12 / min** | Pure cache SQL every minute |

### Top 20 cache offenders (by estimated SQL under database store)

| # | Key / pattern | TTL | Est. SQL/min | Why |
|---|---------------|-----|--------------|-----|
| 1 | `platform:zone:*:snapshot` + overviews | 120–300s | 50–200+ | Every-minute warm has/get/put |
| 2 | `platform:warm:lock:*` | 55s | 10–30 | Lock churn every warm tick |
| 3 | `automation.operations.snapshot[+meta]` | 900s | 8–20 | Cron get/put even when incremental |
| 4 | `automation.operations.snapshot.dirty` | 3600s | 2–6 | Checked every minute + events |
| 5 | `cashfree:webhook:reliability:dashboard_snapshot` | 120s | 2–8 | Merged into automation tick |
| 6 | `gmail.sync.metrics.{date}.{mailbox}.{metric}` | EOD | 10–30+ | Write amplification ×5 metrics |
| 7 | `gmail.access_token.{sha1}` | token−60s | 2–6 | Sync path |
| 8 | `operator.dashboard.snapshot:v2` | 15–30s | 5–20* | *scales with pollers |
| 9 | `operator.dashboard.slow_scalars:v1` | 15–60s | 2–10* | Admin live strip |
| 10 | `incoming_email:dashboard_widget:{date}` | 45s | 2–8* | KPI strip |
| 11 | `operations:dashboard:latest/sections:*` | 30s | 2–15 | OCC live |
| 12 | Ops health widgets / advisor / bonvoice | 30–60s | 4–20 | Ops hub |
| 13 | `operations:scheduler:last_run_at` | 3600s | 2 | Heartbeat |
| 14 | `operations:presence:last_*` | 3600s | 6–8 | 3 puts/min |
| 15 | `ira_assignment_batch:*` + flush locks | batch | 5–15 | Every-minute flush |
| 16 | `app.settings.all` | forever | ≤1/req | Fixed by request memo |
| 17 | `platform:overall-health` / `health:snapshot` | 120s | 4–20 | Admin strip + warm |
| 18 | `ira:operations:snapshot-data:{date}` | 30s | 2–10 | Short TTL churn |
| 19 | `operations:automation-health:aggregation:{date}` | 60s | 1–6 | 60s rebuild window |
| 20 | Cache health probe key | ephemeral | 3/rebuild | put+get+forget |

### Short TTL churn (≤60s)

Keys that force continuous put/get on database store: operator dashboard snapshot/scalars (15–30s), ops dashboard/sections/health widgets (30s), email widget (45s), IRA snapshot-data (30s), automation-health aggregation / briefing / executive metrics / C360 ops-health (60s).

### Request-scoped vs long-lived

| Prefer request-scoped (or already) | Keep long-lived / shared |
|------------------------------------|---------------------------|
| `SettingService` (`$resolved`) | Platform zone + overview snapshots |
| `DashboardSnapshotStore` | `automation.operations.snapshot` (900s) |
| `AssignReferenceBatchCoalescer` | Settings / system_settings forever |
| Case Intelligence / RadiumBox / timeline request caches | OAuth tokens, watchdog fingerprints |
| Repeated gets of short-TTL ops widgets within one HTTP request | Integration aggregates / daily stats |

### Estimated production impact (cache store only)

| Metric | Estimate | Caveat |
|--------|----------|--------|
| MySQL QPS from `cache` / `cache_locks` | **~20–35%** of account SQL after Phases 1–8 | Was much higher pre–SettingService memo on live |
| Account CPU from cache I/O + serialize | **~8–18%** | Does **not** include cold zone/automation rebuild CPU |
| Quiet minute baseline | **~15–40** pure cache SQL (heartbeat + presence + locks + skips) | Scales up sharply when zones expire |

### Redis later — likely reduction (no business-logic change)

| Metric | Likely reduction | Concentrates on |
|--------|------------------|-----------------|
| SQL | **25–40%** overall; **70–90%** on fresh-warm/skip paths; **5–15%** on cold rebuild minutes | 100% of cache-table queries removed |
| CPU | **10–20%** account-wide | Warm-skip minutes, poll churn, metric increments |
| Request latency | **10–25%** cache-heavy admin/ops; **5–15%** warm `/dashboard/live` | Mutations / cold rebuilds smaller gain |

Redis does **not** replace Phase 6 incremental automation or Phase 7 skip-when-fresh — it removes the MySQL tax those paths still pay on hits.

### Recommended roadmap (ops / follow-on — not implemented here)

1. **R0** — Confirm prod `CACHE_STORE` + sample hot keys / value sizes from `cache` table.  
2. **R1** — Introduce Redis; set `CACHE_STORE=redis` (sessions/queue can stay separate).  
3. **R2** — After Redis, safely raise short dashboard/ops TTLs where freshness allows.  
4. **R3** — Batch or reduce `increment` write amplification (Gmail / Cashfree / Bonvoice).  
5. **R4** — Remeasure warm wall + MySQL QPS; validate 25–40% SQL thesis.

### Hit ratio (qualitative, post Phase 1–8)

| Area | Assessment |
|------|------------|
| Platform / automation (intended) | Healthy TTL + warmer — but hits are still SQL today |
| Operator dashboard | Cross-request snapshot v2 works (array payload); short TTL → churn |
| Settings | Forever + request memo — good |
| Duplicate cache | Ops health (30s) vs Platform integration (120s) still separate |
| Critical ops note | `PlatformCachePolicy`: production must use **`CACHE_STORE=redis`** |

---


## 6. Background jobs

### Infrastructure

- **No Horizon.**
- Default queue: **database**.
- Worker modes: scheduler `queue:work … --stop-when-empty --max-time=55` and/or dedicated cron.
- **Production (2026-08-07):** `QUEUE_WORKER_MODE=dedicated_cron` — `queue:work` is **not** inside `schedule:run` (Cron #2).
- Only **3** Job classes: RadiumBox enrichment, driver guide send, work-recognition scan.
- Most async work = **scheduled Artisan commands** + **outbox processor**.

### Scheduler overhead (P0 investigation → Phase 10)

**Investigation:** [p0-laravel-scheduler-investigation.md](./p0-laravel-scheduler-investigation.md) · Canvas [`p0-laravel-scheduler-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-laravel-scheduler-investigation.canvas.tsx)  
**Phase 10 implementation:** [p0-production-cpu-request-inventory.md § Phase 10](./p0-production-cpu-request-inventory.md#phase-10--scheduler-light-tick--cadence-retune-implemented)

| Metric | Before (prod 2026-08-07) | After Phase 10 (model / local) |
|--------|-------------------------:|-------------------------------:|
| Natural `schedule:run` wall | **~12.3 s / min** | **~1–2 s** quiet min; **~3–4 s** amortized |
| Dominant child | warm **~9 s every min** | warm **~9 s every 5 min** |
| Light-job artisan boots | **4–6 / min** | **1 / min** (`schedule:light-tick`) |
| Local light-job wall | **1924 ms** (4 boots) | **514 ms** (1 boot) |
| Parent orchestration | ~5 ms | unchanged |

**Phase 10 shipped:** consolidated light-tick dispatcher; warm **5m**; appointment reminders **5m**; Gmail **2m**; Cashfree recover **15m**; short overlap TTLs on retuned events; heartbeat / queue gate / automation snapshot semantics preserved.

### Scheduler highlights (`bootstrap/app.php`)

| Cadence | Work |
|---------|------|
| Every minute | Heartbeat, queue drain (**only if** `QUEUE_WORKER_MODE=scheduler`), **`schedule:light-tick`** (pending + ira flush + outbox + presence), automation snapshot (bg) |
| Every 2 min | Gmail sync (bg) |
| Every 5 min | Platform warm, infra metrics, deferred smart assignment, appointment reminders, watchdog |
| 15 min / hourly | Cashfree recover, RadiumBox recover, missing serial, Telegram briefings, executive snapshot, IRA risk, automation reconcile |
| Daily | Attendance reconcile, IRA memory, Performance Intelligence snapshot, digests |

### Still on request cycle (move off where possible)

| Work | Today | Recommendation |
|------|-------|----------------|
| Email attention categorization | Sync on dashboard/KPI poll | Precompute on ingest / minute job + cache |
| Priority phrase `matchAndAudit` | Can **write audits during KPI read** | Write only on sync/ingest |
| Case Intelligence build | Sync on IRA AJAX | Cache snapshot; optional warm job |
| Team Activity panel | Sync SSR + poll | Lazy SSR; cache roster metrics 15–30s |
| NotificationDispatcher | Often sync send | Prefer outbox (partially present) |
| Dashboard full incident hydrate | Sync | Projection table / cached snapshot job |
| Gmail sync | **Already background** (`runInBackground`) | Keep off request (confirmed) |

---

## 7. API / AJAX / Livewire

### Livewire

Minimal; **no `wire:poll`** on investigated surfaces. Dashboard/C360 use custom JS.

### Polling matrix (balanced defaults)

| Endpoint / feature | Interval | Payload shape |
|--------------------|----------|---------------|
| `GET /dashboard/live` | 30s | Full `kpi_strip_html` + case rows + filters + online users |
| `GET /dashboard/team-activity` | 30s (expanded only) | Full panel HTML |
| Notifications | 20s | JSON |
| Ops CC live | 30s + full 120s | Section HTML map |
| C360 timeline | 30s | Full timeline HTML |
| C360 device sync | 10s when syncing | Device HTML |
| Presence heartbeat | 120s | POST |
| Email workspace | ~20s | Thread JSON |

### Issues

- **Duplicate polling:** dashboard live + team activity + notifications + presence can overlap for supervisors.
- **Large HTML JSON:** live refresh replaces entire KPI strip HTML (includes Email Intake hover markup).
- **Repeated requests:** C360 Overview → executive-summary; opening AI → second intelligence build; timeline poll without conditional GET.

---

## 8. Frontend

### Assets (measured from `public/build`, 2026-08-05)

| Asset | Size |
|-------|------|
| `app-*.css` | **634 KB** (649,062 bytes) |
| `dashboard-*.js` | **187 KB** |
| `app-*.js` | 76 KB |
| `toast-*.js` chunk | 120 KB |
| Bootstrap Icons woff2 / woff | 131 KB / 176 KB |
| Total `public/build` (approx) | ~1.4 MB |

Source `resources/css/app.css` is monolithic (~432 KB unbuilt) — Team Activity, C360, Ops, agent dashboard all share one CSS entry.

### Findings

| Area | Finding |
|------|---------|
| Blade | Large dashboard + drawer templates; server HTML for live updates |
| Livewire | Not a major cost on these paths |
| JS | Dashboard bundle is the heavy page entry |
| DOM | KPI strip + case rows + Team Activity + drawer host |
| Re-renders | Full HTML swap on live poll (not surgical DOM patch) |
| Blocking | Vite-built modules; Bootstrap Icons fonts |
| Unused | Orphan Blade partials; unused C360 SLA/Ops Health includes |
| Fonts | Bootstrap Icons only (no Google Fonts found in layout) |

### Recommendations (no implementation)

- Split CSS by page entry (dashboard / admin / platform).
- Prefer JSON + client patch for live KPI numbers vs full strip HTML.
- Code-split Customer360 drawer JS if still in dashboard bundle critically path.

---

## 9. Team Activity

### Cost on dashboard

`DashboardController` always calls `TeamActivityPanelService::build()` when user can `teamActivity.view` — **even if UI default is collapsed**. Polling correctly skips when collapsed; **first paint does not**.

### Per build fan-out

For each roster member:

- Operational roster
- Role-aware KPI metrics (effort/outcome aggregates)
- Latest audits + optional expanded history
- Presence / working hours / work sessions
- Bonvoice call metrics by extension
- Pending/overdue via `CaseQueueReadModel::workloadForTeamMembers`
- Performance badges from **persisted** PI snapshots (good — not live scoring)

### Measured

- Test: `build()` with 3 agents ≤ **120 queries** (ceiling, not a success target).
- Production roster of 15–40 agents will multiply audit/presence work.
- Refresh returns **full re-rendered panel HTML**.

### Repeated calculations

- Expanding rows adds counted audits; every refresh with expanded IDs re-queries.
- Pending metrics may overlap conceptually with dashboard case queues (separate read path).
- Badges are cheap (snapshot read); presence + audits dominate.

---

## 10. Email Intake

### Page-load path (DB only — no Gmail API)

```
statsFor()
  → IncomingEmailIntakeCounterService::dashboardWidget()
      → counts()                          # COUNT / ignore stats
      → AttentionCategoryService::aggregateCounts()
          → SELECT * columns FROM incoming_email_messages
             WHERE status IN (NeedsReview, Failed)
          → knownCustomerEmails (Order whereIn)
          → foreach message: categorize()
              → priorityPhraseService->matchAndAudit()  # MAY WRITE audit_logs
```

### Guarantees checked

| Concern | Result |
|---------|--------|
| Gmail API on dashboard HTML | **No** |
| Gmail API on `/dashboard/live` | **No** |
| Gmail on admin intake index | **No** (DB queue) |
| Gmail on Ops Gmail health widget | **No** (sync state tables) |
| Gmail on content open | **Yes** (lazy `IncomingEmailLiveContentService`) |
| Gmail on schedule | **Yes** (`inbound-email:sync-gmail`, background) |

### Hover breakdown

Hover data is built server-side into KPI card HTML. If categorization returns empty arrays, UI still renders title + divider. Combined with markup/CSS bugs below, users may see **only a divider**.

### Routing / Customer360 email

- Admin routing queues: DB queries.
- C360 email modal: lazy thread fetch; content may hit Gmail if body not stored.

---

## 11. Administration

| Page | Cost | Notes |
|------|------|-------|
| Administration home | Low | Cache-only system health summary; no live probes |
| System settings | Medium | Grouped settings + performance profile + config health |
| Operations CC | Medium–High | First-paint groups cached 30s; deferred tabs lazy; profiler exists |
| Platform Mission Control | Medium (Redis) / High (DB cache) | Zone framework; warmer every minute |
| Audit logs | High as table grows | Paginate 20 **but** distinct `user_id` + distinct `events` every index load |
| Performance Intelligence admin | Medium | Snapshot reads; feature-flagged |
| Permissions / roles | Spatie checks per authorize | No bulk preload issue found on admin home |

Slowest admin candidates: **Audit Log index**, **Operations full live refresh**, **Platform with database cache**.

---

## 12. Memory

| Pattern | Location | Effect |
|---------|----------|--------|
| All active incidents + 10+ relations | `DashboardSnapshotStore` | Dominant dashboard RAM; scales with open cases |
| All NeedsReview/Failed emails | `IncomingEmailAttentionCategoryService` | Grows with backlog |
| Full timeline event arrays | C360 timeline / intelligence | Long-lived cases |
| Full email threads + body_html | Email conversation service | Large threads |
| All Bonvoice events for phone | Health + timeline | Chatty customers |
| Collections re-filtered for search count | Dashboard quick search | CPU + allocations |
| Repeated hydration | Live poll every 30s | Steady memory churn under concurrency |
| Blade HTML strings in JSON | Live / C360 AJAX | Peak response size |

---

## 13. Duplicate work

| Duplicate | Occurrences | Shared snapshot recommendation |
|-----------|-------------|-------------------------------|
| Active case KPIs | Operator dashboard vs Platform Operations Snapshot vs IRA memory | One `CaseQueue` / executive projection, multi-reader |
| Incident classification | Snapshot memo OK within request; rebuilt every poll | Cross-request TTL snapshot |
| Case Intelligence | Overview IRA + AI tab | Cache by incident version |
| Timeline merge | Intelligence + Timeline tab + poll | Shared timeline snapshot TTL |
| Action visibility | 2–3× per drawer | Single compute in `buildDrawerData` |
| Serial request state | 2× per drawer | Pass once into overflow |
| Integration health | Ops 30s + Platform 120s | Single health registry |
| Email attention categories | Every load + every live poll | Minute job + cache |
| Team pending vs case queues | Separate services | Derive pending from shared case projection |

**Recommended architecture:** a small set of **shared snapshots**:

1. `operator.dashboard.v1` (15–30s) — active case projection + KPI scalars + email intake counters  
2. `case.intelligence.{incidentId}` (invalidate on incident update)  
3. `case.timeline.{orderId}` (short TTL)  
4. `platform.health` (already exists) as sole integration/infra source  

---

## 14. UX — Email Intake KPI hover only shows divider

### Symptom

Hovering Email Intake KPI shows the tooltip chrome / divider but not the Needs Attention / Ignored rows.

### Root cause candidates (ranked)

**1. Invalid `<dl>` markup (primary — correctness)**  
File: `resources/views/dashboard/partials/email-intake-kpi-card.blade.php`

```html
<dl class="dashboard-email-intake-kpi__hover-list">
  <div class="dashboard-email-intake-kpi__hover-row">
    <dt>…</dt>
    <dd>…</dd>
  </div>
</dl>
<div class="dashboard-email-intake-kpi__hover-divider">…</div>
```

HTML allows only `dt`/`dd` (and script/template) as `dl` children. Wrapping `div`s can cause browsers to repair the DOM such that row content is dropped or not displayed as styled, while the **sibling divider `div` outside `dl` still renders** — matching the reported symptom.

**2. Overflow clipping (secondary — visibility)**  
`.dashboard-kpi-strip { overflow-x: auto; }` (`app.css` ~637–646). Per CSS, non-visible overflow-x forces overflow-y computation that **clips absolutely positioned tooltips** rendered above the card (`bottom: calc(100% + 0.5rem)`, `z-index: 20`).

**3. Empty hover arrays (data)**  
If `aggregateCounts` / ignore stats return zeros and empty row lists, title + divider still show with no rows — looks like “only divider.”

**4. Stacking / transform (tertiary)**  
`.dashboard-u-hover-lift:hover { transform }` creates a stacking context; less likely than markup + overflow.

**5. Positioning**  
Tooltip `position: absolute` inside `position: relative` card is correct; width `min-width: 12rem` should not hide text rows by itself.

### Fix direction (do not implement here)

- Use valid list markup (`div` rows or bare `dt`/`dd`).
- Portals / `overflow: visible` on strip host, or `position: fixed` tooltip.
- Ensure hover payload always includes labeled zero rows for debugging.

---

## Ranked improvements

### Quick Wins (< 1 day)

| # | Improvement | Gain | Risk | Surfaces |
|---|-------------|------|------|----------|
| Q1 | Fix Email Intake hover markup (+ overflow if needed) | UX | Low | Dashboard |
| Q2 | Stop `matchAndAudit` on KPI read path; categorize read-only | High | Low | Dashboard / Email |
| Q3 | Cache Email Intake widget aggregates 30–60s | High | Low | Dashboard |
| Q4 | Lazy-load Team Activity when panel expanded (empty shell on SSR) | High | Low | Dashboard / Team Activity |
| Q5 | Remove/ defer `Order::count`, superadmin full `AuditLog::count` from hot path | Medium | Low | Dashboard |
| Q6 | Dedupe C360 action visibility + serial state | Medium | Low | Customer360 |
| Q7 | Drop `body_html`/`body_text` from email thread list query; SQL limit | Medium | Low | Customer360 |
| Q8 | Audit index: cache distinct users/events or derive from users table | Medium | Low | Admin |
| Q9 | Production confirmed `CACHE_STORE=database` (2026-08-07); switch to Redis when available | High | Medium (ops) | Platform / all caches |
| Q10 | Batch communication action status into 1–2 audit queries | Medium | Low | Customer360 |

### Medium (few days)

| # | Improvement | Gain | Risk | Surfaces |
|---|-------------|------|------|----------|
| M1 | Cross-request operator dashboard snapshot (15–30s) for KPIs + slim rows | High | Medium | Dashboard |
| M2 | Case Intelligence shared cache across executive-summary + AI tab | High | Medium | Customer360 |
| M3 | Timeline source SQL limits + poll conditional/ETag | High | Medium | Customer360 |
| M4 | Universal search: exact/prefix first, disable notes/WhatsApp LIKE by default for short queries; FTS later | High | Medium | Search |
| M5 | Split live poll: scalars vs row HTML; stop full KPI strip HTML when unchanged | Medium | Low | Dashboard / API |
| M6 | Precompute attention category column on email ingest | High | Medium | Email Intake |
| M7 | CSS/JS code-split by surface | Medium | Low | Frontend |
| M8 | Unify executive KPIs with CaseQueue read model | Medium | Medium | Duplicate work |
| M9 | Bonvoice: SQL aggregate / limit recent N calls for health card | Medium | Low | Customer360 |
| M10 | Team Activity metrics cache 15–30s | Medium | Low | Team Activity |

### Major (week+)

| # | Improvement | Gain | Risk | Surfaces |
|---|-------------|------|------|----------|
| J1 | Replace full active-incident hydrate with SQL-paginated queue projection / materialised view | High | High | Dashboard |
| J2 | Full-text / trigram search index strategy | High | Medium | Search |
| J3 | Dedicated queue workers / Redis queue (Horizon or equivalent) | Medium | Medium | Background |
| J4 | Move notification sends fully off request via outbox | Medium | Medium | API latency |
| J5 | Client-rendered C360 tabs (JSON APIs) to cut Blade HTML cost | Medium | High | Customer360 |

---

## Expected gain summary

| Area | Quick wins | After medium | After major |
|------|------------|--------------|-------------|
| **Dashboard** | Often bring supervisors under ~600ms first paint (defer Team Activity + cache Email Intake + drop full counts) | Stable `<600ms` under moderate open-case counts with 15–30s snapshot | Scales with growth via SQL projection |
| **Customer360** | Noticeable drawer open drop (status batching, dedupe) | Overview+IRA closer to `<400ms` with intelligence cache; Timeline polls cheaper | Sub-400ms sustained on large histories |
| **Service Case Search** | Small (intake exact path already good) | Prefix/exact-first can hit `<300ms` for IDs/phones | FTS for fuzzy name/notes |
| **Team Activity** | Hitting `<250ms` if deferred from SSR | Cached metrics keep expanded refresh snappy | — |
| **Administration** | Audit index + Redis | Ops/Platform consistently `<700ms` | — |
| **Overall perceived performance** | **High** — less spinner on dashboard + working KPI hover | **High** — less AJAX churn, smaller polls | **High** — scales with data volume |

### Risk summary

| Theme | Risk |
|-------|------|
| Caching operator case list | Medium — stale queue membership / wrong badge counts |
| Search behavior change | Medium — relevance / missing note hits |
| Projection/materialized queues | High — correctness of SLA/waiting/assignment |
| Redis cutover | Medium — ops dependency; required for Platform design |

---

## Widget / endpoint measurement matrix (for production instrumentation)

Use existing profilers + temporary `DB::listen` in staging (not added by this audit).

### Dashboard widgets

| Widget | Execution time | SQL count | Cache | Repeated? |
|--------|----------------|-----------|-------|-----------|
| Snapshot load | Measure | 1 + eager children | Request only | Every request/poll |
| KPI scalars (snapshot) | Measure | 0 extra | Request | Shared |
| Order/User/Audit counts | Measure | 1–3 COUNT | None | Every statsFor |
| Email Intake widget | Measure | 1 large SELECT + Order whereIn + optional audit writes | **None** | Every load + poll |
| Case list render (35) | Measure | 0 (from snapshot) | Request | + per-row presenters |
| Team Activity | Measure | ≤120 tested / 3 agents | None | SSR always; poll if expanded |
| Live refresh total | Measure | ≈ full index without Team Activity if collapsed | None | 30s |

### Customer360

| Section | Time | SQL | Cache | Notes |
|---------|------|-----|-------|-------|
| Overview drawer | Budget `<500ms` test | High (actions N+1, Bonvoice, summaries) | Request | Target `<400ms` |
| IRA executive summary | Measure | Timeline + AI builders | Request engine only | Lazy |
| Timeline | Measure | ~14 sources unbounded | Request | Poll 30s |
| IRA AI | Measure | Full intelligence again | None cross-request | Lazy |
| Email thread | Measure | 2 unbounded gets | None | Modal |
| Audit POST | Low | 1 insert path | — | Light |
| Attachments | N/A tab | Via email content | Lazy Gmail possible | |
| Perf Intelligence | N/A in C360 | Admin snapshots | Daily | Separate |

---

## Appendix A — Key file index

| Area | Path |
|------|------|
| Dashboard SSR | `app/Http/Controllers/DashboardController.php` |
| Live refresh | `app/Http/Controllers/DashboardLiveController.php` |
| Snapshot | `app/Services/Dashboard/DashboardSnapshotStore.php` |
| Stats / search filter | `app/Services/DashboardService.php` |
| Team Activity | `app/Services/Dashboard/TeamActivityPanelService.php` |
| Email Intake counts | `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` |
| Attention categories | `app/Services/IncomingEmail/IncomingEmailAttentionCategoryService.php` |
| KPI hover view | `resources/views/dashboard/partials/email-intake-kpi-card.blade.php` |
| Customer360 | `app/Services/Customer360Service.php`, `app/Http/Controllers/Customer360Controller.php` |
| Intelligence | `app/Services/Customer360/Intelligence/CaseIntelligenceEngine.php` |
| Timeline | `app/Services/Timeline/Customer360TimelineService.php` |
| Universal search | `app/Services/UniversalSearchService.php` |
| Platform cache | `app/Services/Platform/PlatformCachePolicy.php` |
| Scheduler | `bootstrap/app.php` |
| Performance polls | `config/performance.php` |
| Cache default | `config/cache.php` |
| Composite indexes | `database/migrations/2026_07_02_130000_add_composite_performance_indexes.php` |

---

## Appendix B — What this audit did / did not do

**Did:** Codepath tracing, test-budget evidence, built asset measurement, cache/scheduler inventory, UX root-cause analysis, ranked recommendations.

**Did not:** Modify code, run production EXPLAIN, deploy APM, create a Canvas, implement optimizations, change `CHANGELOG.md`.

---

## Appendix C — Suggested next measurement pass (ops)

1. Enable temporary query/time logging on `DashboardController@index`, `DashboardLiveController@refresh`, `Customer360Controller@show|executiveSummary|timeline`, `SearchController@search` in staging with production-like data volume.  
2. EXPLAIN the top 10 SQL from Appendix ranking.  
3. Confirm `CACHE_STORE`, queue worker mode, and open-case count at peak.  
4. Re-check Email Intake hover in Chrome/Safari after markup validation (View Source vs Elements).  

---

*End of report.*
