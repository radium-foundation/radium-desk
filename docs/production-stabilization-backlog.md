# Production Stabilization Backlog

**Sprint type:** Post-H4 investigation only — **no fixes implemented**  
**Date:** 2026-07-25  
**Scope:** Production stability, bugs, performance, UX  
**Out of scope:** H5 Refresh Platform, architecture redesign, H4 refactors

Items are ordered within each priority band by impact. Effort: **S** (≤1 day), **M** (2–4 days), **L** (1+ week).

---

## P0 — Critical

### P0-1. Reverb partial updates clear agent next-appointment banner

| Field | Detail |
|---|---|
| **Problem** | After Reverb KPI/row events, agent appointment sticky banner disappears until next HTTP poll (60s–5min). |
| **Impact** | Support agents miss upcoming appointments; reported as “appointment widget vanished.” |
| **Root cause** | `applyPartialDashboardUpdate` fires `dashboard:live-refresh` without `next_appointment`; `app.js` treats missing field as `null`. Reverb `DashboardKpisUpdated` never includes appointment data. |
| **Risk** | High — daily operator workflow on Reverb/auto mode. |
| **Effort** | S |
| **Recommended fix** | Omit `next_appointment` from partial event when absent (don't clear), or include `next_appointment` in Reverb KPI payload for agents. |
| **Files** | `resources/js/app.js`, `resources/js/live-dashboard.js`, `app/Events/Dashboard/DashboardKpisUpdated.php`, `app/Services/DashboardBroadcastService.php` |

---

### P0-2. Customer360 timeline polling continues after drawer close

| Field | Detail |
|---|---|
| **Problem** | Timeline `setInterval` is not stopped when drawer closes. |
| **Impact** | Background HTTP requests, CPU drain, wrong-endpoint traffic after operator closes C360. |
| **Root cause** | `close()` calls `stopDeviceSyncPolling()` only; never `stopTimelinePolling()`. |
| **Risk** | High — resource leak on every timeline-tab session. |
| **Effort** | S |
| **Recommended fix** | Call `stopTimelinePolling()` in `close()` and before `loadInitialContent()` when switching incidents. |
| **Files** | `resources/js/customer-360-drawer.js` |

---

### P0-3. Customer360 polls wrong incident after switching customers

| Field | Detail |
|---|---|
| **Problem** | Device/timeline timers capture old `refreshUrl`; opening another incident leaves orphan polls hitting previous incident endpoints. |
| **Impact** | Stale/wrong data shown; duplicate concurrent polls. |
| **Root cause** | `open()` / `loadInitialContent()` does not stop existing timers before starting new ones. |
| **Risk** | High — data integrity in C360 workflow. |
| **Effort** | S |
| **Recommended fix** | Stop all poll timers at start of `open()`; guard refresh callbacks with current `activeIncidentId`. |
| **Files** | `resources/js/customer-360-drawer.js` |

---

### P0-4. KPI strip vs queue row list can diverge during Reverb updates

| Field | Detail |
|---|---|
| **Problem** | Tab counts `(N)` update while row list stays stale (or vice versa). |
| **Impact** | Operators act on wrong queue state; trust erosion in dashboard. |
| **Root cause** | `DashboardKpisUpdated` updates counts only; rows need separate events or `/dashboard/live/rows`. Suppression during search/workspace/quick-filter queues or drops refreshes. |
| **Risk** | High — core operator surface. |
| **Effort** | M |
| **Recommended fix** | On KPI-only Reverb event, reconcile rows for active queue when not suppressed; or coalesce KPI+row refresh for active filter. Add catch-up on workspace/search release. |
| **Files** | `resources/js/live-dashboard.js`, `resources/js/live-dashboard-reverb.js` |

---

### P0-5. IVR incoming call card has no fallback when Reverb is down

| Field | Detail |
|---|---|
| **Problem** | Incoming call popup depends entirely on `IncomingCallReceived` via Echo; bell poll does not invoke `showIncomingCallCard`. |
| **Impact** | Missed incoming call UI during WebSocket outage, stale socket, or non-dashboard pages. |
| **Root cause** | Card host only on dashboard; no poll-path bridge from `NotificationCreated` / bell HTML to floating card. |
| **Risk** | Critical when `hybrid_realtime.incoming_calls` enabled in production. |
| **Effort** | M |
| **Recommended fix** | On bell poll / `NotificationCreated`, detect incoming-call interaction payload and call `showIncomingCallCard`; extend card host to order/workspace layouts or use toast fallback. |
| **Files** | `resources/js/live-notifications.js`, `resources/js/incoming-call-card.js`, `resources/js/realtime-notifications.js` |

---

## P1 — High

### P1-1. Operations partial polls bypass 30s dashboard cache

| Field | Detail |
|---|---|
| **Problem** | 30s poll hits DB on ~75% of cycles; cache only applies to full 120s refresh. |
| **Impact** | OCC CPU/DB load; slow cards under team size. |
| **Root cause** | `OperationsDashboardService` caches only when all 8 sections requested; JS always polls `critical,summary,health,ira_compact`. |
| **Risk** | High at scale; every ops admin tab. |
| **Effort** | M |
| **Recommended fix** | Per-group or per-bundle cache keys with 30s TTL aligned to poll interval. |
| **Files** | `app/Services/Operations/OperationsDashboardService.php`, `resources/js/operations-dashboard.js` |

---

### P1-2. Operations “always-on” poll bundles include heavy team/support work

| Field | Detail |
|---|---|
| **Problem** | Every 30s poll runs `TeamAvailabilityOverview` + `OperationsSupportIntelligence` even when Team/Today tabs inactive. |
| **Impact** | N×member queries + O(users×incidents) workload scan every 30s. |
| **Root cause** | `overview_cards` / `critical_alerts` bundles include `TEAM_AVAILABILITY` and `SUPPORT_INTELLIGENCE`. |
| **Risk** | High — primary ops monitoring surface. |
| **Effort** | M |
| **Recommended fix** | Move team/support intelligence to lazy tab groups only; keep critical strip lightweight. |
| **Files** | `app/Services/Operations/OperationsDashboardSectionBundles.php`, `resources/js/operations-dashboard.js` |

---

### P1-3. Operations Advisor cache bypassed on every live poll

| Field | Detail |
|---|---|
| **Problem** | Advisor insights rebuild every 30s despite 60s cache key. |
| **Impact** | Extra incident query via `OperationsAdvisorSnapshot` on every poll. |
| **Root cause** | Cache read skipped when pre-built dashboard passed from controller. |
| **Risk** | Medium-high — always-on group includes advisor. |
| **Effort** | S |
| **Recommended fix** | Cache advisor by section set + TTL independent of dashboard instance; reuse `DashboardSnapshotStore`. |
| **Files** | `app/Services/Operations/OperationsAdvisorService.php`, `app/Http/Controllers/OperationsDashboardController.php` |

---

### P1-4. Operations SSR + immediate client refresh (double build)

| Field | Detail |
|---|---|
| **Problem** | Page load renders full dashboard server-side, then JS immediately polls again. |
| **Impact** | 2× full build on every OCC page open. |
| **Root cause** | `initOperationsDashboard()` always calls `refreshOperationsDashboard` on boot. |
| **Risk** | Medium — every ops page load. |
| **Effort** | S |
| **Recommended fix** | Skip first client refresh when SSR `generated_at` is fresh (&lt;30s); or defer first poll. |
| **Files** | `resources/js/operations-dashboard.js`, `resources/views/admin/operations/index.blade.php` |

---

### P1-5. Workforce / Team Availability per-member query amplification

| Field | Detail |
|---|---|
| **Problem** | `teamMembers()` fetched 2–3× per request; per-member authority/presence/session queries. |
| **Impact** | Workforce page latency grows linearly with team size. |
| **Root cause** | `members()` and `unavailableMembers()` each query team; `Workforce360Service::team()` queries again; `memberRow()` stacks 4+ services per user. |
| **Risk** | High with 15+ agents. |
| **Effort** | M |
| **Recommended fix** | Single team member fetch; batch work sessions; adopt `CaseQueueReadModel::forTeamMembers()`; dedupe snapshot assembly. |
| **Files** | `app/Services/Operations/TeamAvailabilityOverviewService.php`, `app/Services/Operations/Workforce360Service.php` |

---

### P1-6. Duplicate notification transport (Reverb + HTTP poll)

| Field | Detail |
|---|---|
| **Problem** | Bell poll runs every 20s even when Echo connected; same events handled twice. |
| **Impact** | Duplicate desktop notifications; wasted `/notifications/poll` load. |
| **Root cause** | `initLiveNotifications()` unconditional; no pause when Reverb healthy. |
| **Risk** | Medium — all authenticated pages. |
| **Effort** | S |
| **Recommended fix** | Pause bell poll when Reverb connected; shared dedupe keys for desktop notifications (like `realtime-notifications.js`). |
| **Files** | `resources/js/live-notifications.js`, `resources/js/live-dashboard-reverb.js`, `resources/js/app.js` |

---

### P1-7. Customer360 device poll runs `devicePayload()` twice per request

| Field | Detail |
|---|---|
| **Problem** | `Customer360Controller::device` builds payload twice per poll tick. |
| **Impact** | 2× backend work every 10–30s while sync pending. |
| **Root cause** | `device()` calls `devicePayload()` and `renderDeviceSection()` which calls it again. |
| **Risk** | Medium — active drawer sessions. |
| **Effort** | S |
| **Recommended fix** | Pass single payload into render helper. |
| **Files** | `app/Http/Controllers/Customer360Controller.php`, `app/Services/Customer360Service.php` |

---

### P1-8. KPI broadcast fans out to all `incidents.view` users

| Field | Detail |
|---|---|
| **Problem** | Each `kpisUpdated()` renders KPI HTML per active user. |
| **Impact** | CPU/DB spikes after bulk assign/close; latency for all dashboards. |
| **Root cause** | `DashboardBroadcastService::recipientsExcept()` loads all active users; per-recipient `liveReverbMetricsFor()`. |
| **Risk** | High during bulk operations (partially mitigated by coalesce). |
| **Effort** | M |
| **Recommended fix** | Broadcast scoped recipients only (assignees + watchers); or delta payload without per-user Blade render where counts identical. |
| **Files** | `app/Services/DashboardBroadcastService.php` |

---

### P1-9. Stale WebSocket can appear connected for ~3 minutes

| Field | Detail |
|---|---|
| **Problem** | Dashboard stops updating while indicator shows connected. |
| **Impact** | Operators work on stale queue until watchdog fires. |
| **Root cause** | `DEFAULT_STALE_WEBSOCKET_MS = 180000`; check every 30s. |
| **Risk** | Medium — Reverb deployments. |
| **Effort** | S |
| **Recommended fix** | Lower stale threshold in production; heartbeat tied to last app event; surface stale state in UI. |
| **Files** | `resources/js/live-dashboard-reverb.js` |

---

### P1-10. Operator dashboard hidden-tab / workspace resume without catch-up

| Field | Detail |
|---|---|
| **Problem** | Returning to tab or closing long modal leaves stale data until next poll/event. |
| **Impact** | Missed queue changes during modal/drawer work. |
| **Root cause** | `document.hidden` blocks refresh; workspace queues single payload; no forced reconcile on resume. |
| **Risk** | Medium — common operator pattern. |
| **Effort** | M |
| **Recommended fix** | On `visibilitychange` visible + workspace release: force one `/dashboard/live` reconcile (presence pattern). |
| **Files** | `resources/js/live-dashboard.js`, `resources/js/live-dashboard-polling.js` |

---

### P1-11. IVR popup delayed by global outbox FIFO

| Field | Detail |
|---|---|
| **Problem** | Incoming webhook blocks on unrelated outbox events before alert broadcast. |
| **Impact** | Call rings out before operator sees popup. |
| **Root cause** | Sync outbox drain in webhook HTTP request; no priority lane for Bonvoice. |
| **Risk** | High when outbox backlog exists. |
| **Effort** | M |
| **Recommended fix** | Priority queue for `bonvoice.webhook.process`; or async process with SLA alert on S2 delay. |
| **Files** | `app/Services/Outbox/OutboxProcessorService.php`, `app/Http/Controllers/Webhooks/BonvoiceWebhookController.php` |

---

### P1-12. Incoming call card only on operator dashboard page

| Field | Detail |
|---|---|
| **Problem** | `#incoming-call-card-host` exists only on `dashboard/index`; agents on incident/order pages miss floating card. |
| **Impact** | Missed popup when not on main dashboard. |
| **Root cause** | Blade host scoped to dashboard view only. |
| **Risk** | Medium — depends on agent navigation patterns. |
| **Effort** | M |
| **Recommended fix** | Move card host to global layout (navbar area) for agents with incoming-call permission. |
| **Files** | `resources/views/dashboard/partials/incoming-call-card-host.blade.php`, `resources/views/layouts/app.blade.php` |

---

### P1-13. `/dashboard/live` reloads full active-incident snapshot every refresh

| Field | Detail |
|---|---|
| **Problem** | Poll/Reverb heartbeat latency grows with active case volume + per-row Blade render. |
| **Impact** | Slow dashboard under high load; reconnect storms amplify. |
| **Root cause** | `DashboardSnapshotStore` loads all operationally active incidents each request; `mapServiceCaseRows` renders HTML per row. |
| **Risk** | High at scale. |
| **Effort** | L |
| **Recommended fix** | Short-term: cap row batch + pagination unchanged; medium: KPI-only heartbeat endpoint; long: row diff API. |
| **Files** | `app/Services/Dashboard/DashboardSnapshotStore.php`, `app/Http/Controllers/DashboardLiveController.php` |

---

## P2 — Medium

### P2-1. Operations polls continue when browser tab hidden

| Field | Detail |
|---|---|
| **Problem** | OCC keeps polling at 30s when tab in background. |
| **Impact** | Wasted server load; no user benefit. |
| **Root cause** | No `visibilitychange` handling in `operations-dashboard.js`. |
| **Risk** | Low user impact; medium infra cost. |
| **Effort** | S |
| **Recommended fix** | Pause poll when hidden; reconcile critical group on resume. |
| **Files** | `resources/js/operations-dashboard.js` |

---

### P2-2. Mission Control vs Operations KPI definition divergence

| Field | Detail |
|---|---|
| **Problem** | “Open cases” and “waiting” differ between MC and Ops dashboards. |
| **Impact** | Leadership/ops confusion; support tickets alleging “wrong numbers.” |
| **Root cause** | MC uses SQL `operationallyActive()` + waiting state table; Ops uses queue classifier (documented KEEP SEPARATE). |
| **Risk** | Medium — product/communication issue, not bug. |
| **Effort** | M (docs) / L (unification) |
| **Recommended fix** | UI labels clarifying definitions; optional H4-7 product decision — **do not silently merge**. |
| **Files** | `app/Services/Executive/ExecutiveMetricsContextBuilder.php`, `app/Services/Dashboard/DashboardSnapshot.php` |

---

### P2-3. Executive auto-poll setting unwired

| Field | Detail |
|---|---|
| **Problem** | `executive_dashboard_seconds` exists but Mission Control has no auto-refresh. |
| **Impact** | Stale MC cards unless manual refresh; dead config confuses admins. |
| **Root cause** | `platform-dashboard.js` manual-only; index loads all cards eagerly. |
| **Risk** | Low-medium. |
| **Effort** | M |
| **Recommended fix** | Wire optional interval poll OR remove dead setting; lazy-load expensive cards. |
| **Files** | `resources/js/platform-dashboard.js`, `config/performance.php` |

---

### P2-4. Executive single-card refresh rebuilds all 8 KPIs

| Field | Detail |
|---|---|
| **Problem** | Refreshing one MC card forces full executive snapshot rebuild. |
| **Impact** | Slow card refresh; unnecessary DB. |
| **Root cause** | `DashboardManifest::cardPayload()` calls `refresh(force: true)`. |
| **Risk** | Low-medium. |
| **Effort** | M |
| **Recommended fix** | Per-metric refresh or refresh-once-slice pattern. |
| **Files** | `app/Services/Platform/DashboardManifest.php`, `app/Services/Executive/ExecutiveMetricsService.php` |

---

### P2-5. Orphan Echo listeners for superseded hybrid events

| Field | Detail |
|---|---|
| **Problem** | Client listens to `TransactionAssigned`, `ServiceCaseResolved`, `ServiceCaseClosed` but server broadcasts plural hybrid events only. |
| **Impact** | Dead code paths; confusion during flag toggles. |
| **Root cause** | H3 hybrid migration incomplete on client cleanup. |
| **Risk** | Low unless hybrid flags disabled. |
| **Effort** | S |
| **Recommended fix** | Remove dead listeners or map to hybrid handlers behind feature flag. |
| **Files** | `resources/js/live-dashboard-reverb.js` |

---

### P2-6. Customer360 drawer open — duplicate service calls

| Field | Detail |
|---|---|
| **Problem** | `actionVisibility`, serial state, communication menu computed 2–3× per open. |
| **Impact** | Slow drawer first paint. |
| **Root cause** | `drawerData()` calls same helpers for toolbar, overflow, and sections independently. |
| **Risk** | Medium — every C360 open. |
| **Effort** | M |
| **Recommended fix** | Memoize per-request in `Customer360Service::drawerData()`. |
| **Files** | `app/Services/Customer360Service.php` |

---

### P2-7. Customer360 timeline/AI tabs duplicate heavy work

| Field | Detail |
|---|---|
| **Problem** | Timeline poll and AI tab each rebuild full journey + AI bundle. |
| **Impact** | Slow tab switches; poll amplifies cost. |
| **Root cause** | `timelineTabPayload` / `aiTabPayload` / `executiveSummaryPayload` overlap. |
| **Risk** | Medium. |
| **Effort** | M |
| **Recommended fix** | Shared per-incident request cache for timeline + AI context. |
| **Files** | `app/Services/Customer360Service.php`, `app/Services/Customer360/Customer360TimelineService.php` |

---

### P2-8. Customer360 event handler accumulation on poll refresh

| Field | Detail |
|---|---|
| **Problem** | `bindCopyActions` / sync handlers re-bound without guards after each device poll. |
| **Impact** | Duplicate toasts/clicks after extended drawer session. |
| **Root cause** | Missing `dataset.*Bound` guards (tabs have them; device section does not). |
| **Risk** | Medium. |
| **Effort** | S |
| **Recommended fix** | Add bound guards or delegate events on stable parent. |
| **Files** | `resources/js/customer-360-drawer.js` |

---

### P2-9. Admin “Open” vs “Total Active Cases” KPI confusion

| Field | Detail |
|---|---|
| **Problem** | Two headline numbers use different universes. |
| **Impact** | Operators report KPI inconsistency. |
| **Root cause** | `open_cases` = queue sum; `total_active_cases` = all active incidents. |
| **Risk** | Low — by design. |
| **Effort** | S |
| **Recommended fix** | Tooltip/label clarification in KPI strip; no formula change. |
| **Files** | `resources/views/dashboard/partials/kpi-strip.blade.php` |

---

### P2-10. Dashboard poll config split (`dashboard_live_ms` vs `realtime.polling_interval_*`)

| Field | Detail |
|---|---|
| **Problem** | Admin performance settings don't affect actual operator poll intervals. |
| **Impact** | Ops misconfiguration; false sense of tuning. |
| **Root cause** | Two unrelated config keys; only `realtime.*` wired to blade. |
| **Risk** | Low — ops confusion. |
| **Effort** | S |
| **Recommended fix** | Unify admin UI to show effective intervals; deprecate orphan key. |
| **Files** | `config/system_settings.php`, `app/Services/Realtime/RealtimeRuntimeConfig.php` |

---

### P2-11. Click-to-call disabled by default

| Field | Detail |
|---|---|
| **Problem** | `BONVOICE_CLICK_TO_CALL_ENABLED=false`; UI falls back to `tel:` only. |
| **Impact** | Feature appears broken if env not set in production. |
| **Root cause** | Safe default in `config/bonvoice.php`. |
| **Risk** | Low if intentional; high if operators expect API bridging. |
| **Effort** | S |
| **Recommended fix** | Verify production env; document enablement checklist. |
| **Files** | `config/bonvoice.php`, ops runbook |

---

### P2-12. Hybrid incoming calls disabled by default

| Field | Detail |
|---|---|
| **Problem** | `hybrid_realtime.incoming_calls` system setting off by default. |
| **Impact** | No floating IVR card until admin enables. |
| **Root cause** | Phased rollout default. |
| **Risk** | Low if bell/toast sufficient; high if popup expected. |
| **Effort** | S |
| **Recommended fix** | Production enablement decision + monitoring S0–S7 latency. |
| **Files** | `config/system_settings.php`, `docs/ivr-incoming-popup-latency.md` |

---

### P2-13. Bonvoice agent resolver O(n) user scan per inbound call

| Field | Detail |
|---|---|
| **Problem** | Each IVR webhook loads users with `bonvoice_extension` to match agent. |
| **Impact** | S4 latency grows with user count. |
| **Root cause** | No indexed lookup by extension. |
| **Risk** | Medium at scale. |
| **Effort** | M |
| **Recommended fix** | Cache extension→user map; DB index on `bonvoice_extension`. |
| **Files** | `app/Services/Bonvoice/BonvoiceAgentResolver.php` |

---

### P2-14. OperationsDashboardSnapshot redundant automation/audit queries

| Field | Detail |
|---|---|
| **Problem** | Multiple queries against same automation execution / audit tables per build. |
| **Impact** | Extra DB on full OCC refresh. |
| **Root cause** | Separate `count`, `get`, `exists`, `latest` helpers. |
| **Risk** | Low-medium. |
| **Effort** | M |
| **Recommended fix** | Consolidate into single query + in-memory derivations. |
| **Files** | `app/Services/Operations/OperationsDashboardSnapshot.php` |

---

### P2-15. `DashboardLiveController::refresh` wrapped in DB transaction

| Field | Detail |
|---|---|
| **Problem** | Read-only live endpoint runs inside transaction. |
| **Impact** | Marginal latency; holds connection unnecessarily. |
| **Root cause** | Historical wrapper in controller. |
| **Risk** | Low. |
| **Effort** | S |
| **Recommended fix** | Remove transaction wrapper from read-only path. |
| **Files** | `app/Http/Controllers/DashboardLiveController.php` |

---

## P3 — Nice to have

### P3-1. Adopt `forTeamMembers()` batch open counts in TeamAvailability

| Field | Detail |
|---|---|
| **Problem** | Per-user `forUser()` in loop despite batch API existing. |
| **Impact** | Minor CPU; missed optimization from H4-6D. |
| **Root cause** | Not adopted after ReadModel scope added. |
| **Risk** | Low. |
| **Effort** | S |
| **Recommended fix** | `forTeamMembers($teamMembers)` once per list build. |
| **Files** | `app/Services/Operations/TeamAvailabilityOverviewService.php` |

---

### P3-2. Workforce360 request-scoped cache

| Field | Detail |
|---|---|
| **Problem** | Full `team()` pipeline on every page load. |
| **Impact** | Repeat visits slow. |
| **Root cause** | No cache by design post-H4-6D. |
| **Risk** | Low. |
| **Effort** | M |
| **Recommended fix** | 15–30s TTL for team overview DTO only (not open counts ownership). |
| **Files** | `app/Services/Operations/Workforce360Service.php` |

---

### P3-3. Echo channel explicit teardown

| Field | Detail |
|---|---|
| **Problem** | `stopListening` / `leave` not called on realtime destroy. |
| **Impact** | Theoretical duplicate handlers on re-init. |
| **Root cause** | Relies on full page reload today. |
| **Risk** | Low. |
| **Effort** | S |
| **Recommended fix** | Explicit channel cleanup in `teardown`. |
| **Files** | `resources/js/live-dashboard-reverb.js` |

---

### P3-4. C360 / notification poll pause when tab hidden

| Field | Detail |
|---|---|
| **Problem** | C360 and notification timers continue in background. |
| **Impact** | Wasted requests (operator dashboard pauses fetch but not these). |
| **Root cause** | No visibility handling. |
| **Risk** | Low. |
| **Effort** | S |
| **Recommended fix** | Align with presence-heartbeat pattern. |
| **Files** | `resources/js/customer-360-drawer.js`, `resources/js/live-notifications.js` |

---

### P3-5. Click-to-call: single provider dial attempt per leg

| Field | Detail |
|---|---|
| **Problem** | `legADialAttempts=1`; transient provider failures not retried server-side. |
| **Impact** | Higher failure rate on flaky mobile networks. |
| **Root cause** | Bonvoice API payload config. |
| **Risk** | Low — client retry exists. |
| **Effort** | S |
| **Recommended fix** | Evaluate `legADialAttempts=2` with idempotent `eventID`. |
| **Files** | `app/Services/Bonvoice/BonvoiceClickToCallService.php` |

---

### P3-6. Unknown caller IVR card — no quick-create CTA

| Field | Detail |
|---|---|
| **Problem** | Unknown caller popup links to dashboard only. |
| **Impact** | Extra clicks to create/link incident. |
| **Root cause** | No incident match in resolver. |
| **Risk** | Low — UX polish. |
| **Effort** | M |
| **Recommended fix** | Add “Create case” action when no `incident_id`. |
| **Files** | `resources/js/incoming-call-card.js`, `HybridRealtimeNotificationBroadcaster` |

---

### P3-7. Update stale technical debt docs

| Field | Detail |
|---|---|
| **Problem** | `remaining-technical-debt.md` and `dashboard-architecture.md` partially outdated. |
| **Impact** | Wrong assumptions during stabilization sprints. |
| **Root cause** | Docs not updated after partial OCC bundle fix. |
| **Risk** | Low. |
| **Effort** | S |
| **Recommended fix** | Refresh docs to reflect current bundle/cache behaviour. |
| **Files** | `docs/remaining-technical-debt.md`, `docs/dashboard-architecture.md` |

---

## Click-to-Call — Reference (investigation summary)

**Lifecycle:** UI (`bonvoice-click-to-call.js`) → `POST /bonvoice/click-to-call` → `BonvoiceClickToCallContextResolver` → `BonvoiceClickToCallService` → Bonvoice `autoCallBridging` API.

**Retry:** Server retries once on 401 (token refresh). Client shows Retry toast when `retriable=true`. No server retry for 429/5xx.

**Failure points:** Disabled env (default), missing `bonvoice_extension`, invalid customer phone, provider HTTP errors, single dial attempt per leg.

**Production checklist:** Enable `BONVOICE_CLICK_TO_CALL_ENABLED`; verify credentials/DID; ensure agent extensions populated; monitor `BonvoiceClickToCallMetrics`.

---

## IVR Popup — Reference (investigation summary)

**Lifecycle:** Bonvoice webhook → sync outbox → `BonvoiceLiveCallAssistService::maybeNotify` → `IncomingCallReceived` (S5) → `incoming-call-card.js` (S7). Side paths (S6): operator alert, Telegram, DB notification — non-blocking.

**Race conditions:** Outbox FIFO delay; DB `afterCommit` broadcast; client `shownKeys` dedupe; workspace-busy blocks C360 auto-open.

**Missed popup when:** Hybrid flag off; Reverb down; wrong page (no card host); agent phone mismatch; first webhook is NOANSWER; outbox backlog.

**Instrumentation:** S0–S7 stages in `BonvoiceIncomingCallLatency`; browser S7 in `incoming-call-card.js`. See `docs/ivr-incoming-popup-latency.md`.

---

## Reverb — Audit summary

| Area | Finding |
|---|---|
| Connection lifecycle | Connect → full refresh + heartbeat; disconnect → fast poll; stale watchdog 3min |
| Reconnect | `forceReconnect` on stale; browser `online` event; admin force-reconnect flag |
| Subscriptions | `dashboard.{userId}`, `notifications.{userId}` only |
| Unused events | `TransactionAssigned`, `ServiceCaseResolved`, `ServiceCaseClosed` (client only) |
| Orphan listeners | 3 legacy dashboard events; notification triple-path |
| KPI fan-out | All `incidents.view` users per broadcast |

---

## Recommended sprint execution order

**Week 1 (quick wins, P0 bugs):** P0-2, P0-3, P0-1, P1-7, P1-6, P2-5  
**Week 2 (ops perf):** P1-1, P1-2, P1-3, P1-4, P2-1  
**Week 3 (workforce + IVR):** P1-5, P0-5, P1-11, P1-12  
**Week 4 (deeper):** P0-4, P1-8, P1-13, P1-10  

---

## Regression gates (run before/after each fix)

| Suite | Guards |
|---|---|
| `DashboardReverbMetricsConsistencyTest` | Reverb vs poll KPI parity |
| `CaseQueueOperatorConsumerMigrationTest` | Operator summary counts |
| `OperationsDashboardPerformanceTest` | OCC query budgets |
| `QueueIntegrityLiveRefreshTest` | Queue membership on refresh |
| `BonvoiceLiveCallAssistTest` / `HybridRealtimePhase3Test` | IVR paths |
| `Workforce360Test` / `CaseQueueWorkforceConsumerMigrationTest` | Open work counts |

---

**STOP:** No implementation in this sprint. Await prioritization approval before fixing items.
