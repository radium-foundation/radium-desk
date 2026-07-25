# H4-6C.1 — CaseQueue Summary Adoption Audit

**Phase type:** Production-safety audit (+ optional low-risk migration)  
**Date:** 2026-07-25  
**Status:** Complete — **no additional consumers migrated** (none remaining met SAFE rules)

This audit inventories every production `DashboardSnapshot` consumer after H4-6C and classifies each as SAFE TO MIGRATE or KEEP ON SNAPSHOT.

**Rules (non-negotiable)**

SAFE TO MIGRATE only if **all** apply:

1. Summary counts only  
2. Identical KPI definitions to `CaseQueueReadModel` / `DashboardSnapshot`  
3. No incident collections  
4. No queue membership decisions  
5. No Reverb  
6. No assignment  
7. No workflow decisions  

Do **not** expand `CaseQueueReadModel` (no SQL, cache, or business logic).

---

## 1. DashboardSnapshot consumer inventory

### 1.1 Already on CaseQueueReadModel for summary counts (H4-6C)

| Class | Method | Snapshot still used for | Count methods via ReadModel | Cache | Purpose |
|---|---|---|---|---|---|
| `OperationsSupportIntelligenceService` | `summary()` | `incidentsForQueue(Scheduled)`, `activeIncidents()`, SmartAssignment workload | `queueCounts`, SLA splits | Ops 30s bundle (indirect) | Ops Today / bento operational metrics |
| `IraMemoryService` | `buildSnapshotData()` | — (no load) | `openCount`, queue counts, SLA | `ira:operations:snapshot-data:{date}` 30s | IRA ops summary |
| `IraOwnerIntelligenceService` | morning/evening ops sections | `unassignedImportantCount` via `incidentsForQueue` | `openCount`, waiting, SLA | Via IRA / reports | Owner report summary KPIs |

### 1.2 Remaining `DashboardSnapshot::load()` call sites

| # | Class | Method(s) | Snapshot methods | Counts vs collections | Cache | Business purpose |
|---|---|---|---|---|---|---|
| 1 | `DashboardService` | `snapshot()`, `statsFor()`, filter/SLA helpers, live metrics | `operationalKpiCounts`, `slaCounts*`, `activeIncidents`, `incidentsForQueue/Filter`, `filterCounts` | Both | Request store; Reverb/poll | **Operator dashboard** |
| 2 | `DashboardKpiAggregator` | `supportAgentKpis` etc. | `myWorkIncidents`, `incidentsForQueue`, `activeIncidents` | Collections | Request | Agent cards / personal KPIs |
| 3 | `DashboardLiveRowVisibilityService` | `isVisibleInQueue` | `incidentsForQueue` + contains | Membership | Request | **Reverb** row visibility |
| 4 | `AgentNextAppointmentResolver` | `resolve` | `incidentsForQueue(Scheduled)` | Collections | Request | Next appointment workflow |
| 5 | `TeamAvailabilityOverviewService` | `members`, `unavailableMembers`, `memberSnapshot` | `openCount($user)` only | Summary (scoped) | Request | Ops Team / availability chips |
| 6 | `Workforce360Service` | `member` | `openCount($subject)` only | Summary (scoped) | Request | Workforce member open work |
| 7 | `SmartAssignmentService` | `resolveBestAssignee`, `workloadMetrics`, `scoreCandidates` | `activeIncidents` + classifier | Collections + **assignment** | Request | Assignee selection |
| 8 | `SupportAssignmentWorkloadService` | `forUser`, `forUsers` | `activeIncidents` | Collections + assignment | Request | Assignment workload |
| 9 | `SmartAssignmentFeedbackMetricsService` | `feedbackFor` | via workloadMetrics | Assignment metrics | Request | Assignment feedback |
| 10 | `IraRecommendationEngineService` | `capacityRecommendations` | `incidentsForQueue(Scheduled)`, workloadMetrics | Collections + recommendations | Request | IRA recommendations |
| 11 | `IraRiskDetectionService` | `teamRisks`, `longWaitingCaseCount` | workloadMetrics; `incidentsForQueue(WaitingCustomer)` + age filter | Collections + AI risk | Request | IRA risk detection |
| 12 | `IraCommunicationService` | `unassignedScheduledCount` | `incidentsForQueue(Scheduled)` | Collections | Request | Comms / unassigned scheduled |
| 13 | `TeamWorkBriefingService` | `buildFor`, `supportCountsBySlot` | `incidentsForQueue` + filters | Collections | Request | Team briefing |
| 14 | `SupportSlotReminderService` | `itemsFor` | `incidentsForQueue(Scheduled)` | Collections / list items | Request | Slot reminders |
| 15 | `OperationsSupportIntelligenceService` | helpers under `summary` | `incidentsForQueue`, `activeIncidents` | Collections | Request | Appointment / team helpers |
| 16 | `IraOwnerIntelligenceService` | `unassignedImportantCount` | `incidentsForQueue` | Collections | Request | Owner unassigned important |

**Not `DashboardSnapshot`:** `OperationsAdvisorService` / `OperationsAdvisorSnapshot` use a separate advisor snapshot (own active-incident load). `OperationsDashboardSnapshot` is Ops orchestration, not case-queue membership.

**Store forget-only** (invalidation, not consumers of counts): e.g. `IncidentWaitingStateService`, `OrderController`, business-hold paths — KEEP as invalidation.

---

## 2. Classification

### A. SAFE TO MIGRATE (this phase)

| Consumer | Decision |
|---|---|
| *(none remaining)* | All H4-6C-eligible summary-count consumers already migrated. |

### B. Technically summary-only but **out of H4-6C.1 migrate scope**

| Consumer | Why not migrate now |
|---|---|
| `TeamAvailabilityOverviewService` `openCount($user)` | ✅ Migrated in **H4-6D** via `CaseQueueReadModel::forUser()` |
| `Workforce360Service` `openCount($subject)` | ✅ Migrated in **H4-6D** via `CaseQueueReadModel::forUser()` |

### C. KEEP ON SNAPSHOT

| Consumer | Reason |
|---|---|
| `DashboardService` + live/Reverb paths | Operator dashboard, Reverb publishers, live queue lists |
| `DashboardKpiAggregator` | Agent collection KPIs / membership overlays |
| `DashboardLiveRowVisibilityService` | Queue membership for live rows |
| `AgentNextAppointmentResolver` | Workflow selection from Scheduled membership |
| SmartAssignment* / `SupportAssignmentWorkloadService` | Assignment decisions |
| IRA recommendation / risk / communication helpers | Collections + recommendations / AI |
| `TeamWorkBriefingService`, `SupportSlotReminderService` | List/collection builders |
| SupportIntelligence appointment helpers | Collections |
| IraOwner `unassignedImportantCount` | Collection scan |

---

## 3. Optional migration result

**Zero additional migrations** in H4-6C.1.

`CaseQueueReadModel` was **not** expanded. No SQL, cache, or business logic added.

---

## 4. Approved CaseQueueReadModel consumer allowlist

| Consumer | Status |
|---|---|
| `OperationsSupportIntelligenceService` | ✅ H4-6C (summary counts) |
| `IraMemoryService` | ✅ H4-6C |
| `IraOwnerIntelligenceService` | ✅ H4-6C (summary counts) |
| `TeamAvailabilityOverviewService` | ✅ H4-6D (scoped open) |
| `Workforce360Service` | ✅ H4-6D (scoped open) |

Enforced by:

- `CaseQueueReadModelTest::test_only_allowlisted_case_queue_read_model_consumers_exist`
- `CaseQueueReadModelTest::test_remaining_dashboard_snapshot_load_sites_are_intentional_keep_list`

---

## 5. Is DashboardSnapshot used only where it should remain owner?

**Mostly YES for shared summary KPIs** — Ops/IRA summary counts go through ReadModel.

**YES for summary KPIs** — Ops/IRA global summary + Workforce scoped open go through ReadModel.

Remaining intentional `DashboardSnapshot` usages:

1. Operator dashboard + Reverb / poll (collections + counts)  
2. Assignment / workload engines (collections)  
3. IRA recommendations / risks / briefing helpers (collections)  
4. SupportIntelligence / IraOwner collection helpers  
5. Request-store invalidation (`forget`)  

---

## 6. Risks / rollback

| Item | Note |
|---|---|
| Risk of over-migration | Avoided — Workforce left for H4-6D |
| Rollback | N/A for code (audit-only); allowlist test documents approved consumers |

---

## 7. Next phase

**H4-6D** ✅ — Workforce / TeamAvailability scoped open migrated. **H4-6E** — operator dashboard / Reverb only after SAFE-list review. Keep MC executive open/waiting separate.
