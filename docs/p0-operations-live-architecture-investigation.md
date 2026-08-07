# P0 Operations `/live` — Deep Architectural Investigation

**Status:** Investigate only (no code or config changes)  
**Date:** 2026-08-07  
**Deploy:** `e1370d7` / `e1370d76`  
**Endpoint:** `GET /admin/operations/live` (`admin.operations.live`)  
**Canvas:** [`p0-operations-live-architecture-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-operations-live-architecture-investigation.canvas.tsx)

Related: [p0-lsphp-http-cpu-attribution-investigation.md](./p0-lsphp-http-cpu-attribution-investigation.md) · [p0-http-cpu-spike-0950-investigation.md](./p0-http-cpu-spike-0950-investigation.md) · [p0-production-cpu-request-inventory.md](./p0-production-cpu-request-inventory.md) · [periodic-polling-endpoints-investigation.md](./periodic-polling-endpoints-investigation.md)

---

## Verdict

`GET /admin/operations/live` is the **largest measured HTTP endpoint** on production.

| Metric | Cold / full | Warm / lighter |
|--------|------------:|---------------:|
| Wall | **20.6–20.8 s** | **9.1–9.7 s** |
| SQL count | **5646–5966** | **547–550** |
| SQL time | **~7.1 s** | **~3.1 s** |
| Response | **~151–155 KB** HTML-in-JSON | same shape |
| Peak memory | **~394 MB** | **~126 MB** |

Cold wall ≈ **34% SQL / 66% PHP+Blade**. Warm path still multi-second because section HTML rebuild and collection work remain.

No Livewire. Transport is Blade SSR + browser `fetch` polling (`resources/js/operations-dashboard.js`).

---

## 1. Complete request lifecycle

```
Browser (operations-dashboard.js)
  → GET /admin/operations/live[?groups=…]
  → web middleware + session auth
  → OperationsDashboardController middleware: can(operations-dashboard.view)
  → live(Request)
       ├─ resolveLiveGroups()          // null if no ?groups
       ├─ LiveRenderer::resolveSections(groups)
       ├─ dashboardService->dashboardDataForSections(sections)
       │    ├─ Cache::get(section|full key)  // TTL 30s, DB cache driver
       │    └─ miss → build() | buildForSections() → buildBundles()
       ├─ [if ira sections] iraBrainService->briefing() + formatter
       ├─ [if advisor_insights] advisorService->platformInsights()
       └─ liveRenderer->renderSections() → JSON { generated_at, groups, html }
  → JS applyLiveHtml() replaces DOM section targets
```

### Entry points that call `/live`

| Caller | Groups | Frequency |
|--------|--------|-----------|
| Poller `setInterval` | `critical,summary,queue,operators` | 45s balanced |
| Poller full refresh | *(none)* → all sections | every 180s |
| Boot (SSR stale ≥30s) | first-paint groups | once |
| Deferred idle | `health,ira_compact` | once after boot |
| Tab `shown.bs.tab` | single tab group | once per tab |
| IRA modal | `ira_full` | on open |
| Visibility catch-up | always-refresh groups | on tab visible |

SSR page load (`GET /admin/operations`) builds first-paint sections only and does **not** hit `/live`.

---

## 2. Controller execution

**File:** `app/Http/Controllers/OperationsDashboardController.php`

```php
public function live(Request $request): JsonResponse
{
    $groups = $this->resolveLiveGroups($request);
    $sections = OperationsDashboardLiveRenderer::resolveSections($groups);
    $needsIra = $this->sectionsNeedIra($sections);
    $needsAdvisor = $this->sectionsNeedAdvisor($sections);

    $dashboard = $this->dashboardService->dashboardDataForSections($sections);
    $iraBriefing = $needsIra ? $this->iraBrainService->briefing() : null;
    // … format IRA …
    $advisorInsights = $needsAdvisor ? $this->advisorService->platformInsights(dashboard: $dashboard) : [];

    return response()->json([
        'generated_at' => …,
        'groups' => $groups,
        'html' => $this->liveRenderer->renderSections(…),
    ]);
}
```

| Branch | When |
|--------|------|
| IRA | sections include `ira_compact`, `ira_full_analysis`, `ira_briefing*`, `immediate_risks` |
| Advisor | only `advisor_insights` (not in normal poll/full section lists) |
| Full data | `$groups === null` → `ALL_SECTIONS` → `build()` = **allBundles()** |

---

## 3. Services invoked

### Orchestrator

`OperationsDashboardService::buildBundles()` selectively constructs `OperationsDashboardData` from bundle flags.

**Cache keys**

| Scope | Key | TTL |
|-------|-----|-----|
| Full | `operations:dashboard:latest:v2` | 30s |
| Partial | `operations:dashboard:sections:{xxh128(sections)}` | 30s |

### Bundle → service map

| Bundle | Service | Entry |
|--------|---------|-------|
| `support_intelligence` | `OperationsSupportIntelligenceService` | `summary()` |
| `ivr_analytics` | `BonvoiceAnalyticsService` | `widgets()` |
| `queue_metrics` | `OperationsQueueMetricsService` | `metrics($snapshot)` |
| `team_availability` | `TeamAvailabilityOverviewService` | `overview()` |
| `team_telegram_status` | `OperationsTeamTelegramStatusService` | `members()` |
| `cashfree_health` | `OperationsCashfreeHealthService` | `widget()` |
| `integration_health` | `OperationsIntegrationHealthService` | `cards()` |
| `radiumbox_health` | `OperationsRadiumBoxHealthService` | `widget()` |
| `gmail_health` | `OperationsGmailHealthService` | `widget()` |
| `notification_metrics` | `OperationsNotificationMetricsService` | `metrics()` |
| `automation_metrics` | `OperationsAutomationMetricsService` | `metrics()` |
| `missing_serial_automation` | `OperationsMissingSerialAutomationService` | `qualitySummary()` |
| `cashfree_device_enrichment` | `OperationsCashfreeDeviceEnrichmentService` | `qualitySummary()` |
| recent_* | recent failure/activity/IRA list services | `recent(15)` |
| `system_health` | `OperationsSystemHealthService` | `components()` |

**Outside bundles (controller):**

- `IraOperationsBrainService::briefing()` (+ formatter, reasoning provider name)
- `OperationsAdvisorService::platformInsights()` (advisor section only)

### Always-refresh vs full

**Partial (`ALWAYS_REFRESH_GROUPS`):**

`critical_alerts` + `overview_cards` + `queue_summary` + `active_operators`  
→ bundles: **support_intelligence, ivr_analytics, queue_metrics, team_availability**

**Full (`groups` omitted):**

`isFullRefresh(ALL_SECTIONS)` → `build()` → **`allBundles()`** including Cashfree, integration, gmail, radiumbox, automation/notification metrics, recent lists, etc. — even when rendered HTML for `health_status` is a static Platform link.

---

## 4. Repository / read-model calls

| Consumer | Downstream |
|----------|------------|
| Support Intelligence | `DashboardSnapshot::load()` → `DashboardSnapshotStore` / `OperatorDashboardCache`; `CaseQueueReadModel::{sla,queue}Counts`; appointment SQL counts; missing-serial counts |
| Queue metrics | `OperationsDashboardSnapshot` memoized counts or `QueueMetricsService::capture()` |
| Team availability | `CaseQueueReadModel::forTeamMembers`; WorkSession; LeaveRequest; `WorkforceAuthorityService`; `PresenceEngineService` |
| Cashfree health | `CashfreeIntegrityReadModel` → `CashfreePaymentIntegrityService`; `CashfreeIntegrationHealthProbe`; `CashfreeHealthService::status` |
| IVR | Bonvoice call-event aggregates + agent join |
| IRA briefing | Support Intelligence + roster + `IraRiskDetectionService` + `IraRecommendationEngineService` (+ may write `ira_operational_memory_snapshots`) |

There is no separate “Operations repository” layer; SQL is owned by these services and Eloquent models.

---

## 5. Blade rendering

**Renderer:** `OperationsDashboardLiveRenderer::renderSections`  
**Views:** `resources/views/admin/operations/partials/*`

| Section key | Partial | Data weight |
|-------------|---------|-------------|
| `critical_alerts` | critical-alerts | SI + optional IRA |
| `overview_cards` | overview-cards | SI + IVR + team on_duty + advisor |
| `queue_summary` | queue-summary-compact | queue metrics |
| `active_operators` | active-operators-compact | team availability |
| `ira_compact` | ira-briefing-compact | IRA |
| `health_status` | health-status-compact | **static shell** (props unused) |
| `today_tab` / `team_tab` / `performance_tab` / `system_tab` | *-tab | large |

Response shape: JSON map of section → HTML string. Client replaces by element id (`SECTION_TARGETS` in JS).

---

## 6. Livewire / AJAX

| Mechanism | Used? |
|-----------|-------|
| Livewire / `wire:` | **No** under `resources/views/admin/operations` |
| AJAX | **Yes** — `fetch(liveUrl[?groups=…])` |
| Other AJAX | Automation health/pipeline on separate routes; RadiumBox batch recovery POST |

---

## 7. Polling intervals

| Setting | Config key | Balanced default |
|---------|------------|-----------------:|
| Partial poll | `performance.polling.operations_ms` | **45_000 ms** |
| Full refresh | `performance.polling.operations_full_refresh_ms` | **180_000 ms** |
| Fetch timeout | JS `FETCH_TIMEOUT_MS` | **30_000 ms** |
| SSR freshness skip | JS `SSR_FRESHNESS_MS` | **30_000 ms** |

Profiles (via `PerformanceRuntimeConfig`): high-performance **15s / 60s**; balanced **45s / 180s**; low-resource **60s / 240s**.

Visibility: interval cleared when hidden; catch-up refresh + restart when visible.

**No in-flight lock** — concurrent polls possible (especially 15s profile vs 9–21s responses).

---

## 8. Background API calls

From the OCC page, `/live` does **not** call external APIs in the measured probes (no outbound-wait signature). Cost is MySQL + PHP.

Related background systems that share Cashfree integrity work:

- `GET /admin/automation` (same integrity family, ~5s / 226 SQL)
- Cron `cashfree:auto-recover-missing`, webhook processing (not part of `/live` request)

IRA briefing may invoke a reasoning provider on cold cache (separate from SQL N+1).

---

## 9. Database queries

### Production TOP SQL (heavy cold probe)

| Pattern | Count | SQL ms | Class |
|---------|------:|-------:|-------|
| `incident_waiting_states` by `incident_id` | 1894 | 205 | N+1 |
| `orders` by id | 1886 | 239 | N+1 |
| `exists(orders.cashfree_payment_id)` | 346 | 19 | N+1 (probe) |
| `orders.cashfree_payment_id IN (…)` | 216 | 1437 | Aggregate batch |
| `orders.order_id IN (…)` | 216 | 323 | Aggregate batch |
| `cashfree_webhook_logs.cf_payment_id IN (…)` | 216 | **3263** | Aggregate batch |
| `leave_requests` by user/date | 198 | 25 | Per-member |
| `company_holidays` exists | 110 | 10 | Per-member |
| `cache` SELECTs | 53 | n/m | DB cache driver |

### Duplicate / repeated work

1. **CashfreeHealthService::build** calls `classifyFailedWebhooks`, `probe`, `paidWithoutDeskOrderCount`, then `requiresCashfreeHealthAlert()` which **re-runs** paid-missing + failed classification.
2. **Full refresh** also builds `integration_health`, whose Cashfree card repeats probe + classify + paid-missing + alert.
3. **`paidWithoutDeskOrderCount`** loads **all** webhook logs (`successfulPaymentLogsByCfPaymentId`), then chunked IN lookups (`LOOKUP_CHUNK_SIZE = 500`).
4. **`CashfreeIntegrationHealthProbe`** loads failed logs and calls `classifyFailedLog($log)` **without** assessment index → per-log `exists()`.
5. Support Intelligence + IRA cold path can both walk active-incident / team workload logic.

### Eager loading

`DashboardSnapshotStore` eager-loads `order.*`, `activeWaitingState`, `activeBusinessHold`, `supportAppointments`, etc. Production still shows ~1890× waiting/order queries on cold/full — **residual N+1** (secondary collections, rehydration edge cases, and/or `slaStatus`/`hasSlaPaused` on models without loaded relations). Store intent ≠ measured path.

### Aggregates / counts / EXISTS / joins

- Missing-serial: 4× `orders` counts  
- Device enrichment: 2× order counts + audit JSON count  
- Queue: pending/running/retry counts (+ optional `QueueMetricsService::capture`)  
- IVR: grouped aggregates + JSON extraction  
- Cashfree: mass `EXISTS` + chunked `IN` (dominant SQL time)  
- Holidays: repeated `EXISTS`  
- Appointments: `whereHas(incident)` counts  

---

## 10. Loops

| Loop | Location | Effect |
|------|----------|--------|
| Classify every active incident | `DashboardSnapshot` / `CaseQueueReadModel` / Support Intelligence | O(active cases); triggers N+1 if relations missing |
| `slaStatus` / `hasSlaPaused` per pending case | SLA count helpers | Waiting-state access per case |
| Team `memberRow` × N | `TeamAvailabilityOverviewService` | Leave/session/holiday probes |
| Failed webhook × classify | Cashfree probe | exists N+1 |
| Successful payment logs × assess | `paidWithoutDeskOrderCount` | Full table load + chunked IN |
| Blade section foreach | `LiveRenderer::renderSections` | Re-render all requested sections |
| Recipient workload scans | Support Intelligence / IRA recommend | O(team × incidents) PHP |

---

## 11. Business logic cost centers

| Logic | Cost |
|-------|------|
| Queue classification | Per-incident; waiting/order/automation status |
| SLA bands | `SettingService::getInt` (memoized; not stampede here — only ~53 cache SQL) + business-hours calculator |
| Cashfree integrity categories | Payload parse + order/payment/webhook presence |
| Team duty authority | Calendar + leave + presence composition |
| IRA risk/recommend | Extra incident loads (`withCount(remarks)`), weekly counts, workload loops |
| Permission | Single middleware check — **not** a hotspot |

---

## 12. Caching opportunities

| Asset | Current | Opportunity |
|-------|---------|-------------|
| Dashboard sections | 30s | Keep; raise TTL for slow bundles (Cashfree/IVR) |
| Operator active incidents | 15–30s array projection | Ensure relations survive decode; avoid re-classify storms |
| Cashfree health widget | 30s | Request-scoped memo + 60–120s shared integrity cache with automation |
| IRA briefing | 60s | Already; ensure full refresh does not force cold path unnecessarily |
| Automation aggregation | 60s | OK |
| Bonvoice widgets | 60s | OK |
| Team availability | request-only | Short cross-request cache (30–60s) |
| Schema::hasTable | unmemoized | Request-level table-existence memo |
| DB `CACHE_STORE` | database | Redis (infra) removes cache SQL tax |

---

## 13. Deferred loading opportunities

| Component | Today | Better |
|-----------|-------|--------|
| First paint | critical/summary/queue/operators SSR | Keep |
| health + ira_compact | deferred idle | Keep IRA; **drop health from deferred** (static) |
| Tabs | lazy on shown | Keep; do not full-refresh all tabs every 180s |
| Cashfree / RadiumBox / Telegram details | JS hooks exist; Blade has no lazy markers | Load only on Platform or explicit expand |
| Performance-tab metrics | pulled on full refresh | Only when Performance tab opened |
| System recent lists | full refresh | Only on System tab |
| Advisor | unused on normal path | Keep unused |

---

## 14. Components that do **not** need live refresh

- `health_status` compact (static link to Platform)
- IRA full analysis (modal, on-demand)
- Automation embed (separate routes)
- Gmail/Cashfree/Telegram detail widgets (not mounted in current compact health)
- Advisor insights section (not in poll groups)

---

## 15. Components that should refresh independently

| Component | Suggested cadence |
|-----------|-------------------|
| Critical alerts + queue summary | 30–45s |
| Overview / support intelligence | 45–60s |
| Active operators | 60–90s |
| IVR widgets | 60s (or with Performance tab) |
| IRA compact | 60–120s (own cache) |
| Today / Team / Performance / System tabs | on open + 60–180s while active |
| Cashfree integrity | 120s+ shared cache; not every full OCC poll |
| RadiumBox health | 60–120s on demand |

---

## Call graph (condensed)

```
live()
├─ resolveSections(groups)
├─ OperationsDashboardService.dashboardDataForSections
│  ├─ Cache get/put (30s)
│  └─ buildBundles
│     ├─ [SI] SupportIntelligence → DashboardSnapshotStore → CaseQueueReadModel
│     ├─ [IVR] BonvoiceAnalyticsService.widgets
│     ├─ [Queue] OperationsQueueMetricsService (+ Snapshot)
│     ├─ [Team] TeamAvailabilityOverviewService → WorkforceAuthority/Presence
│     ├─ [Full] CashfreeHealth + IntegrationHealth + RadiumBox + Gmail + …
│     └─ missing_serial (shared into SI when both requested)
├─ IraOperationsBrainService.briefing          // ira sections
└─ OperationsDashboardLiveRenderer.renderSections → Blade HTML map
```

---

## Time breakdown

| Layer | Cold (20.8s) | Warm (9.1s) |
|-------|-------------:|------------:|
| SQL | ~7.1 s (34%) | ~3.1 s |
| PHP hydrate / classify / integrity | large | large |
| Blade HTML render | large | large |
| External HTTP | negligible in probe | negligible |

Warm still ~9s ⇒ **caching alone is insufficient**; must shrink work per poll and HTML rebuild.

---

## N+1 report (summary)

| ID | Path | Query | Scale | Sev |
|----|------|-------|------:|-----|
| N1 | Classifier / `hasSlaPaused` | `incident_waiting_states` | ~1894 | P0 |
| N2 | Classifier order access | `orders` by id | ~1886 | P0 |
| N3 | Cashfree probe `classifyFailedLog` | `exists` payment/order/webhook | ~346 | P0 |
| N4 | Team duty checks | leave / holidays | ~198 / ~110 | P1 |
| N5 | Cashfree build duplication | full integrity scans ×2–3 | chunks×216 | P0 |
| N6 | integration_health × cashfree_health | same stack twice | ×2 | P0 |

---

## Root causes (ranked)

1. **Full refresh → `allBundles()`** pulls Cashfree integrity storms + every heavy widget even when UI does not show them (`health_status` static).
2. **Incident waiting_state + order N+1** during classify/SLA (~3800 queries).
3. **Cashfree duplication** (widget + probe without index + `requiresCashfreeHealthAlert` re-entry + integration card).
4. **Always-refresh includes Support Intelligence + Team + IVR every 45s** — warm floor ~9s.
5. **Blade HTML re-render** of large sections every poll (~66% cold wall).
6. **Per-member workforce probes**.
7. **No in-flight coalescing** under aggressive poll profiles.
8. **DB cache driver** (minor vs above).

---

## Ranked optimization roadmap

| Prio | Action | Est. save | Risk |
|------|--------|-----------|------|
| **P0-1** | Map full refresh sections → **exact bundles only**; never build Cashfree/integration for static health shell | 40–60% cold | Med |
| **P0-2** | Eliminate waiting/order N+1 on every classify collection; batch SLA pause checks | ~3800 queries | Low–Med |
| **P0-3** | Cashfree: request memo; probe uses batched index; shared 60–120s integrity cache with automation | exists×346 + duplicate scans | Med |
| **P1-1** | Split poll cadences (critical/queue vs team vs IVR vs Cashfree) | Always-on CPU ↓ | Low |
| **P1-2** | Remove IVR from ALWAYS_REFRESH overview path | Partial rebuild ↓ | Low |
| **P1-3** | Bulk leave/holiday/session for team availability | ~300 queries | Low |
| **P2-1** | Prefer JSON metrics over Blade HTML for stable cards | Warm wall ↓ | Med UI |
| **P2-2** | In-flight lock / skip overlapping `/live` | Overlap pile-up | Low |
| **P2-3** | Redis cache driver | Cache SQL tax | Infra |

**Do not implement until explicitly approved.**

---

## Probe appendix (raw)

```
GET /admin/operations/live  ms=[20520.3,9058] sql=[5660,547] bytes=154823
  detail heavy: sql=5966 sql_ms=7131 wall=20832 mem_peak=394MB

Kernel ops/live (09:50 investigation): ms=[20597.9,9688.4] sql=[5646,550] kb~150.6

POLL_OPS_MS=45000 POLL_OPS_FULL_MS=180000 CACHE=database
```

Sources: production HttpKernel probes documented in `p0-lsphp-http-cpu-attribution-investigation.md` and `p0-http-cpu-spike-0950-investigation.md`.
