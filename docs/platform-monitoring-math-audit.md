# Mission Control Monitoring Mathematics Audit

**Scope:** Monitoring accuracy only · **No implementation** · Production live

**Rules:** No UI redesign · No workflow / scheduler / queue changes · No false-positive-preserving math

---

## Business rule (target state)

| Category | Owns | Must NOT reduce |
|----------|------|-----------------|
| A Runtime Health | Scheduler, workers, queue, DB, cache, storage, heartbeat | Business / config |
| B Integration Health | Live API / channel availability | Config-only gaps |
| C Business Health | Critical cases, refunds, waiting, SLA, exec KPIs | Runtime |
| D Configuration | Missing keys, Not Configured, disabled flags | Runtime score |
| E Diagnostics | Logs, audit, webhook explorer, automation history | Any health % |

**Never allow one category to directly reduce another category’s score.**

---

## 1. Dependency graph (current)

```text
Overall Mission Health %  (platform:overall-health, TTL 120s)
├── Platform Health (weight 2)
│   └── worst(Scheduler, Presence, Queue, AutomationProbe, DB, Cache, Storage)
│       ├── Scheduler ← operations:scheduler:last_run_at (heartbeat every 1m, TTL 3600s)
│       ├── Presence ← presence timeout heartbeat + stale sessions
│       ├── Queue ← QueueMetricsService::capture() live
│       ├── AutomationProbe ← automation_executions + settings flag (24h failures / 2h stale)
│       └── DB / Cache / Storage ← live probes
├── Integration Health (weight 2)
│   └── worst(RadiumBox, Cashfree, Gmail, Interakt, ZeptoMail, Telegram, Meta)
│       └── NotConfigured (Meta) severity 30 can win overall → mapped Unavailable → score 0
└── Executive Snapshot (weight 1)
    └── worst(exec metric cards)
        ├── Critical Cases ≥3 → Critical
        ├── Customers Waiting ≥10 → Critical
        └── Refund Queue ≥5 → Critical

NOT in Overall % (zone-only):
├── Automation zone ← AutomationHealthStatusCalculator (+ Scheduler&Workers runtime probes after prior fix)
├── Performance zone ← queue/notifications/automation/ivr metrics worst()
└── Communications zone ← derived from Integration channel items + notification failures expand
```

Warm cadence: `platform:snapshots:warm` every minute.

---

## 2. Score formulas (current)

### Overall Health

**Provider:** `PlatformOverallHealthService::scorePercent`  
**Cache:** `platform:overall-health` · TTL **120s** · cache-only on paint; recomputed on warm  
**Update:** every warmer cycle (~1m)

```
credit(status) = Healthy→weight · Warning→0.5×weight · Critical/Unavailable→0
score% = round( Σ credit / Σ weight × 100 , 1 )
overall_status = worst(available contribution statuses)
```

| Contributor | Weight | Source | Credit when bad |
|-------------|--------|--------|-----------------|
| Platform Health | **2** | Zone snapshot / `platform:health:overview` | Warning→1.0; Critical→0 |
| Integration Health | **2** | `platform:integration-health:overview` | NotConfigured→Unavailable→**0** |
| Executive Snapshot | **1** | Zone snapshot | Critical→**0** |

**Proven production failure mode (prior capture):** Warning(2)×0.5 + Unavailable(2)×0 + Critical(1)×0 = **20%** while scheduler heartbeat was fresh.

### Platform Health (infra probes)

**Provider:** `PlatformHealthCardProvider` → `PlatformHealthRegistry::probeAll` → `PlatformHealthStatus::worst`  
**Cache:** `platform:health:overview` + zone snapshot · TTL **120s**

| Probe | Thresholds | Category |
|-------|------------|----------|
| Scheduler | null→Critical; >10m Critical; ≥3m Warning | A Runtime |
| Presence | stale sessions Critical; timeout run >10m Critical; ≥3m Warning | A Runtime |
| Queue | failed>0 Critical; pending>50 or oldest>30m Warning | A Runtime |
| Automation (probe) | 24h failure Warning; ≥2h since run Warning; scheduler setting off→Disabled | Mixed A/C |
| DB / Cache | fail Critical; ≥200ms Warning | A Runtime |
| Storage | unwritable Critical | A Runtime |

### Integration Health

**Provider:** `PlatformIntegrationHealthOverviewService` · `IntegrationHealthStatus::worst`  
**Cache:** overview + per-item · TTL **120s**  
**Mapped to Overall:** NotConfigured/Disabled → `Unavailable` (score **0**, still `available: true`)

| Item | Config vs live | Category |
|------|----------------|----------|
| Meta | Always NotConfigured today | **D Configuration** |
| Interakt / Telegram / ZeptoMail | Keys + enabled flags + ops signals | B + D mix |
| Cashfree / Gmail / RadiumBox | Ops health widgets | B Integration |

### Automation Health (zone / ledger)

**Calculator:** `AutomationHealthStatusCalculator`  
**Cache:** `operations:automation-health:aggregation:{date}` TTL **60s**; zone overview TTL **300s**

| Priority | Condition | Status | Problem |
|----------|-----------|--------|---------|
| 1 | pending oldest ≥1h **or** last exec ≥120m | Failed | Message always says “Scheduler stalled”; pending orphans ≠ runtime |
| 2 | failures_today > 0 | Warning | Often business race (“already closed”) |
| 3–4 | no success / success age ≥60m | Warning | Low traffic ≠ outage |

Scheduler & Workers card (after prior fix) uses heartbeat+queue probes — **not** this calculator.

### Performance (zone only — not in Overall %)

**Service:** `PlatformPerformanceOverviewService` · TTL **300s**  
worst(queue failed>0 Critical / pending>50 Warning, notifications, automation metrics, IVR)

### Communications (zone only — not in Overall %)

**Service:** `PlatformCommunicationsOverviewService` · TTL **300s**  
Derived from Integration channel items (gmail/interakt/telegram/zeptomail) + expandable notification failures.

### Executive / Business thresholds

| Metric | Warning | Critical | Category |
|--------|---------|----------|----------|
| Critical Cases | ≥1 | **≥3** | C Business |
| Customers Waiting | ≥4 | **≥10** | C Business |
| Refund Queue | ≥1 | **≥5** | C Business |
| Open Cases / Agents / Orders / Resolved / Appointments | always Healthy | — | C display |

---

## 3. Metric classification (target ownership)

### A — Runtime Health
Scheduler heartbeat · Presence pipeline · Queue workers · DB · Cache · Storage · (optional CPU/memory if added later)

### B — Integration Health
Cashfree · Gmail · Telegram · Interakt · SMTP/ZeptoMail · RadiumBox · **API availability / live failures only**

### C — Business Health
Critical Cases · Refund Queue · Customers Waiting · SLA · Executive KPIs · ops backlog

### D — Configuration
Missing API keys · SMTP log-driver · Meta Not Configured · Disabled feature flags · Missing webhooks

### E — Diagnostics
Audit logs · Webhook explorer · Automation history/ledger · Tools catalog  
**Must never feed health %**

---

## 4. False positive inventory

| Source | Symptom | Class | Ownership bug |
|--------|---------|-------|---------------|
| Meta NotConfigured | Integration overall NotConfigured → Overall credit **0** | Wrong weight / ownership | D bleeds into Overall as if B failed |
| Executive Critical Cases ≥3 | Zone Critical with 170 cases | Bad threshold | C reduces Overall / Mission % |
| Customers Waiting ≥10 / Refund ≥5 | Same | Bad threshold | C → Overall |
| Automation pending orphans | Failed + “Scheduler stalled” | Wrong message + bug | Ledger hygiene ≠ A Runtime |
| Automation 24h “already closed” | Platform Automation probe Warning | Wrong severity for business race | C noise in A probe |
| Queue any failed_jobs > 0 | Critical | Possibly sticky DLQ noise | Threshold/ops hygiene |
| Communications inherits Meta? | No (channels exclude meta) | OK | — |
| Performance not in Overall | N/A | OK | Zone-only today |

---

## 5. Proposed Overall redesign (do not implement)

Expose **separate** scores; never merge categories into one polluted %.

| Score | Inputs | Suggested weight into Mission |
|-------|--------|-------------------------------|
| Runtime Health % | Category A probes only | High |
| Integration Health % | Category B live only; **exclude** NotConfigured/Disabled from denominator | High |
| Configuration Health % | Category D presence (informational; missing ≠ outage) | Display / soft |
| Business Health % | Category C KPIs (ops attention, not infra) | Separate strip |
| Executive Health % | Optional alias of Business or KPI subset | Separate |
| **Overall Mission Health** | Weighted **Runtime + Integration (+ optional soft Config)** only | **Must ignore ticket backlog** |

### Target formulas (proposal)

```
Runtime%     = credit(A probes) / weight(A)
Integration% = credit(B live items) / weight(B live)   # skip NotConfigured
Config%      = configured_count / expected_count       # never zeros Runtime
Business%    = credit(C metrics) / weight(C)           # NOT in Runtime
Mission%     = (2×Runtime + 2×Integration) / 4         # example; Config/Business excluded
```

### Before / after scoring (same production snapshot)

| Lens | Before (today) | After (proposed) |
|------|----------------|------------------|
| Scheduler heartbeat fresh | Buried | Runtime Healthy |
| Meta not configured | Drags Integration→0 credit | Config gap only |
| 170 critical cases | Executive Critical → Mission 20% | Business Critical; Mission stays high if A/B healthy |
| Orphan pending automations | Automation Failed “stalled” | Diagnostics/ledger warning; Runtime from heartbeat |
| **Overall shown** | **~20% Critical** | **Runtime ~Healthy; Mission ≫20%** |

---

## 6. Production risks (if / when changing math)

| Risk | Notes |
|------|-------|
| Operators miss backlog | Keep Business Health highly visible — just don’t call it “platform down” |
| Alert volume drop | Critical Alerts must still surface Business separately |
| Cache semantics | Keep keys/TTLs initially; change contribution mapping only |
| Monday prod | Ship P0 messaging/exclusion first; defer threshold retunes |

---

## 7. Safe implementation order (recommendations only)

### P0 — Safe today
1. Exclude `NotConfigured` / `Disabled` from Integration → Overall credit (or mark `available:false` for scoring).  
2. Stop mapping Executive Business Critical into Overall Mission % (or weight 0 for Mission).  
3. Fix Automation Failed detail: distinguish pending-stale vs heartbeat stall (message-only).

### P1 — Low risk
4. Split Overall UI into Runtime % vs Business % (display).  
5. Retune Critical Cases / Waiting / Refund thresholds or move to “attention” not Critical-for-infra.  
6. Automation probe: don’t Warning Platform Health on benign “already closed” alone (or severity Information).

### P2 — Medium
7. Formal category registries (A–E) with hard boundaries.  
8. Configuration Health % panel (reuse Settings config summary signals).  
9. Pending orphan cleanup job (data hygiene — careful, not math).

### P3 — Future
10. Per-category SLO dashboards · anomaly baselines · operator trust surveys.

---

## 8. Estimated improvement in operator trust

| Change | Trust effect |
|--------|--------------|
| Mission % no longer tanks on ticket count | **High** — stops “platform is 20%” panic |
| Meta NotConfigured stops zeroing Integration weight | **High** — config ≠ outage |
| Honest Automation stall messaging | **Medium** — fewer false “scheduler dead” |
| Separate Business strip | **Medium–High** — backlog still visible, correctly labeled |
| Combined estimate | **Large reduction in false-positive Mission Critical**; trust recovery if Runtime stays green when infra is green |

---

## 9–10. Deliverable note

This Markdown and the Canvas contain **identical content**.  
**Do not implement** until an approved P0 patch plan is scheduled for production.

Canvas: `platform-monitoring-math-audit.canvas.tsx`
