# H4-6A — Case Queue Inventory & Ownership Analysis

**Phase type:** Investigation only  
**Date:** 2026-07-25  
**Status:** Complete — **no production code changed**

This document inventories every case-queue KPI, status, cache, and realtime path before any `CaseQueueReadModel` implementation. It is the production-safety gate for H4-6B+.

**Canonical owners today**

| Concern | Owner |
|---|---|
| Queue membership | `OperationsQueueClassifier` |
| Queue counts / collections | `DashboardSnapshot` (+ request `DashboardSnapshotStore`) |
| Agent personal KPI presentation | `DashboardKpiAggregator` |
| Ops bento / Today facets | `OperationsSupportIntelligenceService` (reads snapshot) |
| Mission Control open / waiting | `ExecutiveMetricsContextBuilder` via `ExecutiveKpiReadModel` (H4-5) — **different definitions** |
| Case automation pipeline | `ServiceCaseAutomationHealthService` — **not OperationQueue** |
| Execution ledger | `AutomationExecutionReadModel` — **not case queue** |
| Infra job depth | `OperationsQueueMetricsService` / Laravel `jobs` — **not case queue** |

`CaseQueueReadModel` (`app/ReadModels/Cases/CaseQueueReadModel.php`) is live for **H4-6C Ops summary counts** + **H4-6D Workforce scoped open** (TeamAvailability / Workforce360 `open_work_count`). Counts still owned by `DashboardSnapshot`; membership by `OperationsQueueClassifier`. Operator dashboard / Reverb / assignment / MC remain unmigrated.

---

## 1. Queue ownership matrix

### 1.1 Operator dashboard KPIs

| KPI name | Owner service | SQL / query source | Cache owner | Reverb owner | Polling owner | Consumers | Business definition | Filters / statuses | Duplicate? |
|---|---|---|---|---|---|---|---|---|---|
| **Open** (`open_cases`) | `DashboardSnapshot::openCount()` | In-memory over request snapshot (active incidents loaded once in `DashboardSnapshotStore`) | Request `DashboardSnapshotStore`; forget via `DashboardService::forgetSnapshot()` | `DashboardBroadcastService` → `DashboardKpisUpdated` | `GET /dashboard/live` | KPI strip; live JS | **Unscoped:** Ready + Scheduled + Exceptions (`ActionRequired` + `Scheduled` + `Attention`). **Scoped user:** `openIncidents($user)` — excludes Waiting / Completed / Hardware | Snapshot = `IncidentStatus::operationallyActive()` load | **Different** from MC `open_cases`. **Internal:** unscoped open ≠ scoped `openIncidents` (PendingReview / BusinessHold) |
| **Customer Waiting** (`waiting_cases`) | `DashboardSnapshot::waitingCount()` | Classifier `WaitingCustomer` count | Request store | Same KPI event | Same live | KPI strip; Waiting tab | Active waiting state **or** legacy automation serial-wait; must be pending-admin | See classifier | **Different** from MC `customers_waiting` |
| **Total active** | `DashboardKpiAggregator::activeIncidentKpis()` | All snapshot rows | Request | Via KPI HTML | Live | Admin KPI strip | Entire operationally-active load | All active statuses | ≠ Open formula |
| **My active / waiting_for_admin / HP** | `DashboardKpiAggregator` | Filters on snapshot | Request | Via strip | Live | stats | Assignee slices | Pending-admin = order not transaction-locked | — |
| **My Work count** | `DashboardKpiAggregator::supportAgentKpis` + `matchesMyWork` | Virtual My Work overlay | Request | Agent cards | Live | Agent action cards | Assigned + (appt today OR waiting OR hardware OR AR/Scheduled/Attention/BusinessHold OR in-progress non-ready) | Support roles | Overlaps My Work queue |
| **My needs attention** | Aggregator | Attention ∪ assigned waiting follow-ups | Request | Agent cards | Live | Agent cards | UI label “Action Required” **≠** Ready Queue | Attention + WaitingCustomer | KEEP SEPARATE from Ready |
| **Overdue / Warning** | `DashboardSnapshot::slaCounts()` | SLA overlay on pending-admin | Request | KPI strip | Live | Operator + Ops intelligence | Does **not** change queue membership | Pending-admin only | Shared Ops SLA risk |
| **Queue / tab counts** | `queueCounts()` / `filterCounts()` | Classify-once warm path; Admin Ready visibility filter | Request | Filter count variants in Reverb | Live | Queue tabs | Per `OperationQueue` + legacy filters | See §2 | Admin Ready count may ≠ raw ActionRequired |

Note: `DashboardService::resolveKpiScopeUser()` currently returns `null`, so the global Open KPI is always the unscoped Ready+Scheduled+Exceptions sum.

### 1.2 OperationQueue buckets (membership source of truth)

| Bucket (enum) | UI label | Owner | Definition (summary) | Counted where |
|---|---|---|---|---|
| `action_required` | Ready Queue | `OperationsQueueClassifier::isReadyForReferenceEntry` | Ready for reference entry | Operator Ready; Ops `action_required`; IRA |
| `attention` | Exceptions | `isAttention` | Validation failed / unassigned HP / automation pending patterns | Operator Exceptions; agent attention |
| `scheduled` | Scheduled / Appointments | `isScheduled` | Pending-admin + scheduled appt with `preferred_date >= today` | Operator + Ops |
| `waiting_customer` | Waiting Customer | `isWaitingCustomer` | Active `IncidentWaitingState` **or** legacy automation serial wait | Operator + Ops waiting |
| `business_hold` | Business Hold | `isBusinessHold` | Pending-admin + active business hold | Admin queues |
| `hardware` | Hardware | `isHardware` | Hardware order-id prefix | Admin queues |
| `pending_review` | Pending Review | `isPendingReview` / default | Stale unassigned backlog (`backlog_stale_hours`, default 18h) | Admin queues |
| `completed` | Completed | `isCompleted` | Inactive incident **or** order transaction-locked | Completed tabs |
| `my_work` | My Work | `matchesMyWork` | **Virtual** overlay — not primary classify | Support My Work |

**Classify priority** (`computeClassification`): Completed → Hardware → BusinessHold → WaitingCustomer → Scheduled → ActionRequired → Attention → PendingReview (default).

**Admin Ready visibility overlay** (not classification): `ServiceCaseAssignmentService::isVisibleInAdminReadyQueue` — excludes certain manual ownership when counting unscoped Ready.

### 1.3 Operations / IRA / Workforce

| KPI | Owner | Source | Cache | RT | Poll | Definition vs operator |
|---|---|---|---|---|---|---|
| Ops `action_required` | `OperationsSupportIntelligenceService::summary` | `$snapshot->queueCounts()[action_required]` | Ops dashboard 30s when bundled | None | `admin.operations.live` | **Identical** to Ready count |
| Ops `waiting` | same | WaitingCustomer count | same | None | same | **Identical** to operator waiting |
| SLA risk splits | same | `snapshot->slaCounts` | same | None | same | **Identical** |
| Appointment facets (`scheduledToday`, etc.) | same | Mix SQL appointments + Scheduled queue | same | None | same | Appointment-centric; MC appointments path separate |
| IRA `open_cases` / waiting / action_required | `IraMemoryService::buildSnapshotData` | DashboardSnapshot + support summary | `ira:operations:snapshot-data:{date}` 30s | None | Ops IRA groups | open = operator unscoped Open |
| SmartAssignment `open_cases` | `SmartAssignmentService::workloadMetrics` | Assigned ∩ (Ready ∪ Exceptions) | Request | None | Ops team | **Narrower** than Workforce open |
| Workforce / Team `open_work_count` | `TeamAvailabilityOverviewService` / Workforce360 | `CaseQueueReadModel::forUser($user)->openCount()` (H4-6D; owner still Snapshot) | Request | None | Ops team / Workforce | Scoped openIncidents |

### 1.4 Mission Control (H4-5) — do not merge blindly

| KPI | Owner | Formula | Cache | vs case queue |
|---|---|---|---|---|
| MC `open_cases` | `ExecutiveMetricsContextBuilder` | `COUNT` status ∈ `operationallyActive()` (**includes** Waiting + Hardware universe of active statuses) | `executive:metrics:snapshot:{period}:{day}` 60s | **KEEP SEPARATE** — larger / different than operator Open |
| MC `customers_waiting` | same | `IncidentWaitingState` where `cleared_at IS NULL` | same 60s | **KEEP SEPARATE** — waiting-state rows ≠ classifier WaitingCustomer |

Facade: `ExecutiveKpiReadModel` (H4-5 ✅) — pure delegate; must not become CaseQueue source without product sign-off.

### 1.5 Explicitly out of case-queue scope

| Surface | Owner | Why excluded |
|---|---|---|
| Automation Health / Pipeline strip | `ServiceCaseAutomationHealthService` / snapshot builder | Case **pipeline** statuses, not OperationQueue |
| Automation executions today/fail/avg | `AutomationExecutionReadModel` | Ledger table |
| Laravel jobs pending/failed | Ops queue metrics / snapshot | Infra queue |
| Refund pending (MC / operator strips) | Refund models / aggregators | Payment domain |
| Administration Home | Static links | No queue KPIs |

---

## 2. Status matrix

### 2.1 IncidentStatus

| Status | Value | `operationallyActive()` | Loaded into DashboardSnapshot | Business meaning | Where counted |
|---|---|---|---|---|---|
| Open | `open` | Yes | Yes | Active service case | All queue paths that load active |
| In Progress | `in_progress` | Yes | Yes | Work underway | Same |
| Awaiting Product Details | `awaiting_product_details` | Yes | Yes | Blocked on product details | Same |
| Resolved | `resolved` | No | No (lifetime SQL elsewhere) | Done | Aggregator status counts; Completed path if locked |
| Closed | `closed` | No | No | Closed | Same |

**`isPendingAdmin()`** (Incident model): order exists and is **not** transaction-locked — gate for Ready/Scheduled/BusinessHold/SLA overlays; **not** an enum status.

### 2.2 OperationQueue (operational buckets)

| Queue | Business meaning | Primary dashboards |
|---|---|---|
| Ready (`action_required`) | Ready for reference / admin action | Operator Ready; Ops Today/bento; IRA |
| Exceptions (`attention`) | Needs exception handling | Operator Exceptions; agent attention |
| Scheduled | Future/today appointment scheduled | Operator Scheduled / Appointments |
| Waiting Customer | Waiting on customer response | Operator Waiting (support); Ops waiting KPI |
| Business Hold | Business hold active | Admin queues |
| Hardware | Hardware order path | Admin Hardware |
| Pending Review | Stale unassigned backlog | Admin Pending Review |
| Completed | Inactive or transaction-locked | Completed tabs |
| My Work | Personal support work overlay | Support My Work |

### 2.3 WaitingReason (why waiting — not queue bucket)

`serial_number`, `payment`, `invoice`, `customer_approval`, `photos`, `device_pickup`, `other`, `customer_not_responding`.

Classifier membership uses **existence** of active waiting state (plus legacy serial automation path), not reason enum for the WaitingCustomer **count**.

### 2.4 Names that look like statuses but are not IncidentStatus

| Label in UI / docs | Actual meaning |
|---|---|
| PENDING_REFUND | RefundRequest pending — not OperationQueue |
| PART_REQUIRED / FOLLOW_UP / ESCALATED | Not first-class OperationQueue values; may appear in attention heuristics or automation |
| CANCELLED | Not an IncidentStatus |

### 2.5 Role → queue surfaces (“My / Team / Supervisor”)

There is **no** Supervisor role. Mapping:

| Concept | Implementation |
|---|---|
| My Queue / My Work | Support roles; `OperationQueue::MyWork`; agent cards |
| Team | Legacy module “Team Service Cases” |
| Admin / ops “supervisor” queues | Superadmin / Admin / Operations Admin → Ready, Exceptions, Scheduled, Hardware, …; Waiting Customer tab often hidden for admin in personalization |

---

## 3. Consumer map

```
Operator Dashboard
  DashboardController / DashboardLiveController
    → DashboardService → DashboardSnapshot + DashboardKpiAggregator
    → Views: kpi-strip, recent-service-cases, agent-action-cards
    → JS: live-dashboard-reverb.js + live-dashboard-polling.js

Operations Control Center
  OperationsDashboardController (poll live)
    → OperationsDashboardService
      → OperationsSupportIntelligenceService → DashboardSnapshot
      → TeamAvailabilityOverviewService → scoped openCount
      → IraMemoryService → snapshot + support summary

Mission Control
  Platform cards → ExecutiveKpiReadModel → ExecutiveMetricsService
    (open_cases / customers_waiting — SEPARATE definitions)

Workforce360
  Workforce360Service / TeamAvailability → scoped openCount

Automation surfaces
  Pipeline / Health — NOT OperationQueue consumers for case membership

Administration Home
  Links only — no queue KPIs
```

---

## 4. Cache map

| Cache key / scope | TTL | Invalidation | Owner | Queue relevance |
|---|---|---|---|---|
| Request `$snapshot` in `DashboardSnapshotStore` | Request | `forget()` (+ classifier memo clear); broadcast paths | `DashboardSnapshotStore` | **Primary** operator queue state |
| Classifier memoization | Request | `forgetClassifications` | `OperationsQueueClassifier` | Membership |
| `operations:dashboard:latest:v2` | 30s | TTL | `OperationsDashboardService` | Bundles support intelligence facets |
| `ira:operations:snapshot-data:{date}` | 30s | TTL / invalidate helper | `IraMemoryService` | IRA open/waiting/action_required |
| `operations:advisor:platform` | 60s | TTL | `OperationsAdvisorService` | Insights linking to queues |
| `executive:metrics:snapshot:{period}:{day}` | 60s | refresh / force / forget | `ExecutiveMetricsCache` | MC open/waiting only |
| `automation.operations.snapshot` | 60s | TTL | Automation ops snapshot | Pipeline — not OperationQueue |

**No** Redis app cache for raw operator `queueCounts` themselves — request snapshot + Reverb/poll rebuild.

---

## 5. Realtime map

### Reverb (operator dashboard only)

| Event | Channel | Payload | Trigger |
|---|---|---|---|
| `DashboardKpisUpdated` | `private-dashboard.{userId}` | KPI strip HTML + filter count variants | `DashboardBroadcastService::dispatchKpisUpdated` after snapshot forget |
| Row events (`ServiceCaseCreated`, remarked, SLA, …) | same | Row HTML + `incidentQueue` | Row broadcast; membership from classifier |
| Hybrid assignment/resolve/close | same | id + queue | **No** full KPI refresh by design |

**JS:** `resources/js/live-dashboard-reverb.js` applies KPI strip + scoped filter variants.  
**Fallback poll:** `live-dashboard-polling.js` → `GET /dashboard/live` (~30s active / 60s idle).

### Operations

| Transport | Endpoint | Interval | Case queue |
|---|---|---|---|
| HTTP poll only | `admin.operations.live` | ~30s (`operations_ms`) | Support intelligence / team open via bundles |
| Reverb | — | — | **Not used** for Ops case queue |

---

## 6. Duplicate analysis

| Pair | Relationship | Classification | Justification |
|---|---|---|---|
| Operator waiting ≡ Ops/IRA waiting | Same `waitingCount` / WaitingCustomer | **SAFE TO CONSOLIDATE** | Identical definition via DashboardSnapshot |
| Ops `action_required` ≡ Ready count | Same queueCounts key | **SAFE TO CONSOLIDATE** | Identical (incl. admin visibility when applied) |
| SLA overdue/warning Ops ≡ operator | Same `slaCounts` | **SAFE TO CONSOLIDATE** | Shared snapshot method |
| Workforce open_work ≡ scoped `openCount($user)` | Same method | **SAFE TO CONSOLIDATE** | Identical scoped open |
| Queue tab badges ≡ filterCounts / Reverb variants | Same methods | **SAFE TO CONSOLIDATE** | Same snapshot |
| MC open_cases vs operator Open | Different universes | **KEEP SEPARATE** | MC = all operationallyActive; operator Open = Ready+Scheduled+Exceptions |
| MC customers_waiting vs WaitingCustomer | Different formulas | **KEEP SEPARATE** | Waiting-state rows vs classifier (+ legacy automation path) |
| SmartAssignment open vs Workforce open | Different filters | **KEEP SEPARATE** | AR∪Attention assigned vs broader openIncidents |
| Unscoped `openCount` vs `openIncidents()->count()` | Internal inconsistency | **KEEP SEPARATE** until product decides | PendingReview / BusinessHold only in latter |
| Agent “Action Required” card vs Ready Queue | Different sets | **KEEP SEPARATE** | Attention ∪ waiting follow-ups ≠ Ready |
| Automation waiting_for_customer_serial vs WaitingCustomer | Partial overlap | **KEEP SEPARATE** | Pipeline status ≠ operational queue |
| Execution ledger / jobs depth vs case queue | Different domains | **KEEP SEPARATE** | Not case membership |

**Doc correction:** H4-1 inventory text that equates MC `customers_waiting` with “waiting-customer queue” is **incorrect vs code**.

---

## 7. Recommended ownership model

```
OperationsQueueClassifier     ← membership rules (only place that decides bucket)
        ▲
DashboardSnapshot             ← counts / collections / SLA overlays (request store)
        ▲
CaseQueueReadModel (future)   ← pure-delegate DTO projection; NO SQL; NO cache
        ▲
┌───────┼────────────────────────────┐
│       │                            │
Operator  Ops Support Intel / IRA   Workforce open_work
KPI/UI    (identical facets only)

ExecutiveKpiReadModel         ← KEEP parallel for MC open/waiting until product unifies
ServiceCaseAutomation*        ← KEEP parallel (pipeline)
AutomationExecutionReadModel  ← KEEP parallel (ledger)
```

**Principles**

1. One membership owner: classifier.  
2. One count owner for operational queues: `DashboardSnapshot`.  
3. Future ReadModel only projects; never recalculates.  
4. Executive / automation / infra queues never silently absorb case-queue formulas.  
5. Reverb/poll continue to call the same stats path (via facade later) — no transport change in H4-6.

---

## 8. Migration order (minimize production risk)

| Step | Scope | Risk | Gate |
|---|---|---|---|
| **H4-6A** ✅ | This inventory + definition locks | None | Product acknowledges KEEP SEPARATE pairs |
| **H4-6B** ✅ | `CaseQueueReadModel` pure-delegate + shadow parity tests | Low | Parity tests green; **no** consumer switch |
| **H4-6C** ✅ | Point Ops SupportIntelligence + IraMemory/IraOwner **summary counts** to facade | Low | Same numbers asserted; collections unchanged |
| **H4-6D** ✅ | Point Workforce / TeamAvailability open_work to facade | Low | Scoped open parity |
| **H4-6E** | Point operator `DashboardService` to facade | Medium | Reverb + poll consistency suite |
| **H4-6F (optional)** | Product-led unify MC open/waiting | High | Explicit definition change + MC copy/thresholds |
| **Defer** | Fix unscoped Open vs openIncidents | High | Product decision on PendingReview / BusinessHold |

Do **not** start with MC ↔ Ops unification.

---

## 9. Production risks

| Risk | Severity | Mitigation |
|---|---|---|
| Silent merge of MC open/waiting into CaseQueue | **Critical** | KEEP SEPARATE until product sign-off |
| Changing Ready visibility filter while facading | High | Preserve `isVisibleInAdminReadyQueue` exactly |
| Reverb KPI strip drift after consumer switch | High | Keep `DashboardReverbMetricsConsistencyTest` + Ready Queue suites as gate |
| Ops 30s cache serving stale facets after snapshot forget | Medium | Existing TTL behaviour; do not add nested cache on ReadModel |
| Renaming UI “Action Required” (agent) vs Ready Queue | Medium | Preserve labels; document dual meaning |
| Treating automation pipeline as queue | Medium | Out of scope for CaseQueueReadModel |

---

## 10. Refactoring strategy

1. **Inventory first** (this doc) — done.  
2. **Shadow facade** — `CaseQueueReadModel` wraps existing snapshot methods; dual-read tests; zero UI change.  
3. **Identical consumers only** — Ops waiting/action_required, IRA facets, Workforce scoped open.  
4. **Operator last** — highest Reverb/poll surface area.  
5. **Never invent statuses** (no PENDING_REFUND / PART_REQUIRED as OperationQueue).  
6. **Never add ReadModel cache** — request store + existing Ops/IRA/Executive TTLs remain sole owners.  
7. **Rollback** — delete facade + restore injections; no schema/route/config rollback.

---

## Feature preservation checklist (H4-6A gate)

- [x] All OperationQueue buckets inventoried  
- [x] IncidentStatus vs queue buckets distinguished  
- [x] MC vs operator definition deltas documented  
- [x] Cache + Reverb + poll maps complete  
- [x] SAFE TO CONSOLIDATE vs KEEP SEPARATE classified  
- [x] No production code modified  
- [x] No ReadModel created  

**Next phase:** H4-6E — operator dashboard / Reverb consumers only after SAFE-list review (still KEEP SEPARATE from MC open/waiting).
