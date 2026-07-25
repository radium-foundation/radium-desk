# Super Admin Four-Hub Consolidation

Architecture and migration plan for consolidating Super Admin surfaces into four operational hubs **without reducing operational capability**.

Routes, permissions, controllers, and business logic remain canonical until a later phase explicitly aliases, relocates, or retires a surface **after production validation and explicit approval**.

**Document status:** H0 (planning) · H1 (sidebar) complete · H2 (Administration Home) complete · H3A–H3C (hub navigation) complete · **H4-1–H4-5 complete** · **H4-6A–H4-6C (Case Queue) complete through Ops summary migration**

---

## Mandatory principle: Feature Preservation Rule

During the Super Admin consolidation, **no existing KPI, card, widget, dashboard section, report, operational feature, shortcut, or tool may be removed** simply because it appears duplicated or underused.

Every existing capability must first be:

1. **Inventoried**
2. **Mapped to its business purpose**
3. **Assigned a long-term owner**
4. **Assigned a destination within the four-hub architecture**

### Allowed outcomes only

| Outcome | Description |
|---|---|
| **Keep as-is** | Same route, same UX; may rename labels only |
| **Relocate** | Move entry point or section; old URL remains alias |
| **Merge** | Combine presentation; all underlying data/actions preserved |
| **Convert into a tab** | Same content inside a hub home tab |
| **Convert into a drawer** | Same content in offcanvas/modal |
| **Hide behind progressive disclosure** | Collapse, “show details”, lazy load — still reachable |
| **Mark as deprecated** | Only after production validation |
| **Remove** | Only after explicit approval |

### Hard constraints

- **Never remove functionality during H2 or H3.**
- **Existing functionality must remain available until the replacement has been validated in production and explicitly approved for retirement.**
- Objective: improve navigation and information architecture **without reducing operational capability**.

---

## Hub home architecture (locked)

Mature dashboards **are** hub homes. Do **not** add thin landing pages on top of them.

| Hub | Hub Home route | Hub Home page | New shell in H2? |
|---|---|---|---|
| **Mission Control** | `admin.platform.index` | Platform Command Center | **No** |
| **Operations** | `admin.operations.index` | Operations Control Center | **No** |
| **Workforce** | `workforce.index` | Workforce360 team | **No** |
| **Administration** | `admin.administration.index` | Administration Home | **Yes — implemented H2** |

### Sidebar primaries (target)

| Hub section | Primary nav → Hub Home |
|---|---|
| Mission Control | `admin.platform.index` |
| Operations Hub | `admin.operations.index` |
| Workforce Hub | `workforce.index` |
| Administration | `admin.administration.index` |

Secondary sidebar links remain during H2/H3 transition; they are **not** removals — they preserve discoverability until in-hub navigation replaces them.

### Principles (unchanged)

- One Super Admin application
- RBAC + scope-based access (Spatie permissions / policies / Workforce360 self-team-member)
- Feature flags for optional modules (System Settings + hybrid realtime map)
- Card-first UI, drawer-first workflow, independent card loading/refresh
- Prefer nav + deep links before service merges

---

## Permission matrix (hub primaries)

| Hub primary | `admin` | `operations_admin` | `superadmin` |
|---|---|---|---|
| Mission Control (`platform-dashboard.view`) | No | Yes | Yes |
| Operations (`operations-dashboard.view`) | Yes | Yes | Yes |
| Workforce (`workforce360.viewTeam`) | Yes | Yes | Yes |
| Administration (`system-settings.manage` + related) | Yes | Yes | Yes |
| Application Settings (`settings.*`) | No | No | Yes |

**Known asymmetry:** plain `admin` cannot open Mission Control today. Hub work must not grant new permissions unless explicitly approved.

---

## Migration phases

> **Preservation requirement (all phases H2–H5):** Existing functionality must remain available until the replacement has been validated in production and explicitly approved for retirement.

| Phase | Deliverable | Preservation notes | Rollback |
|---|---|---|---|
| **H0** | This document + Feature Preservation Register | Inventory only | Delete doc |
| **H1** ✅ | Sidebar regroup into 4 hubs; all old routes live | No removals | Revert sidebar |
| **H2** ✅ | **Administration Home** (`admin.administration.index`) — permission-gated card grid linking to Users, System Settings, Application Settings, Audit Logs, Roles note, Integrations placeholder | All Administration routes unchanged; cards are links only | Remove Administration Home route; restore sidebar without Administration primary |
| **H3A** ✅ | Workforce hub navigation: hub nav tabs (Team, Performance, Leave, Holidays) with deep links; remove duplicate Workforce from operator section for admin roles only | All workforce routes unchanged; sidebar Workforce Hub children preserved | Revert hub-nav partial; restore operator Workforce link |
| **H3B** ✅ | Operations hub navigation: hub nav (Today, Team, Performance, System + Automation Health, Automation, Webhook Explorer deep links) on Control Center and child pages; sidebar unchanged | All operations routes unchanged; inner Control Center tabs preserved | Revert operations hub-nav; remove `hub_tab` JS helper |
| **H3C** 📋 | Operations consolidation — see [super-admin-h3c-operations-consolidation.md](super-admin-h3c-operations-consolidation.md). **H3C-1** ✅ · **H3C-2A** ✅ · **H3C-2B** ✅ · **H3C-3** ✅ sidebar cleanup; H3C-4+ pending | All routes preserved as alias entry points | Per sub-phase rollback |
| **H3** *(umbrella)* | Remaining H3C items below | Every H3 merge keeps URL aliases; no feature deletion | Per sub-phase rollback |
| **H4** | Mission Control lazy first paint; placeholder cards → deep links; shared KPI read facades (no metric loss) | Placeholders become links to existing surfaces, not deletions | Revert providers; keep aliases |
| **H4-1** ✅ | KPI ownership & read model inventory — see [super-admin-h4-1-kpi-read-model-inventory.md](super-admin-h4-1-kpi-read-model-inventory.md) | Inventory only; no code changes | Delete doc |
| **H4-2** ✅ | Automation Health shared aggregation cache — 60s owner cache in `AutomationHealthService` (`overview`, `breakdown`, `failures`); activity/filters uncached; TTL-only invalidation | KPI values unchanged; standalone + embedded Automation tab share cache via `dashboardData()` | Revert `AutomationHealthService` cache methods; delete cache tests |
| **H4-3** ✅ | `AutomationExecutionReadModel` — read-only DTO facade over Health overview aggregation; Ops Performance metrics + activity summary + advisor counts consume shared KPIs (no duplicate COUNT SQL) | Controllers/routes/UI unchanged; Ops-only `partial_success` stays local | Remove ReadModel + restore prior Ops metrics SQL; revert Advisor to dashboard metrics array |
| **H4-4** ✅ | `CashfreeIntegrityReadModel` — pure-delegate projection over `CashfreePaymentIntegrityService`; Ops health widget / integration card / reliability integrity fields / watchdog counts / IntegrationHealthService | Zero formula change; outbox/probe/evening/spike paths intentionally untouched; no ReadModel cache | Delete ReadModel + DTO; restore prior IntegrityService injections |
| **H4-5** ✅ | `ExecutiveKpiReadModel` — pure-delegate projection over `ExecutiveMetricsService`; Mission Control cards + snapshot capture | Zero formula/TTL change; Ops/Admin not migrated (definitions differ or no KPIs) | Delete ReadModel + DTO; restore `ExecutiveMetricsService` injections on cards/snapshot |
| **H4-6A** ✅ | Case Queue inventory & ownership analysis — see [super-admin-h4-6a-case-queue-inventory.md](super-admin-h4-6a-case-queue-inventory.md) | Investigation only; no code | Delete inventory doc |
| **H4-6B** ✅ | Shadow `CaseQueueReadModel` + `CaseQueueMetricsV1` — pure-delegate over `DashboardSnapshot` / classifier; **zero production consumers** | No dashboard/Reverb/UI change; parity tests only | Delete ReadModel + DTO + shadow tests |
| **H4-6C** ✅ | Ops summary consumers → `CaseQueueReadModel` (SupportIntelligence operational metrics, IraMemory ops counts, IraOwner summary counts only) | Collections/lists unchanged; operator/Workforce/MC untouched | Restore prior `DashboardSnapshot` count calls in the three services |
| **H4-6C.1** ✅ | CaseQueue summary adoption audit — see [super-admin-h4-6c1-case-queue-summary-adoption-audit.md](super-admin-h4-6c1-case-queue-summary-adoption-audit.md); **no further SAFE migrants** | Workforce scoped open deferred to H4-6D; allowlist locked | Delete audit doc / restore allowlist tests |
| **H4-6D** ✅ | Workforce scoped open → `CaseQueueReadModel` (`global`/`forUser`/`forTeamMembers`); TeamAvailability + Workforce360 summary counts only | No Team Eloquent model; no collections/assignment/operator/Reverb | Restore `DashboardSnapshot::openCount($user)` in the two services |
| **H5** | Optional renames; deprecated nav cleanup; consolidation polish | Retirements only with explicit approval + production validation | Keep aliases permanently |

### H2 scope (refined)

**In scope**

- Administration Home route + view (static card grid, zero aggregate queries on load)
- Sidebar: Administration primary → `admin.administration.index`
- Temporary secondary Administration links (System Settings, Users, Audit, Application Settings)

**Out of scope**

- Operations or Workforce hub shells
- Mission Control URL or layout changes
- Merging Automation / Automation Health pages
- Removing any KPI, widget, tab, drawer, filter, or action
- Permission or route deletions

### H3A scope (implemented)

**In scope**

- Workforce hub top navigation on `workforce.index`, Team Performance, Holidays, and Leave Requests (admin roles only)
- Tabs deep-link to existing routes (no embedding)
- Team sub-tabs limited to Overview + Timeline on hub home
- Remove duplicate Workforce link from operator sidebar for `admin`, `operations_admin`, `superadmin` only

**Out of scope (H3C+)**

- Inline embedding of Performance / Leave / Holidays pages
- Collapsing Workforce or Operations Hub sidebar children
- Administration H3 work

### H3B scope (implemented)

**In scope**

- Operations hub top navigation on Control Center, Automation Health, Automation Operations, Webhook Explorer
- Control Center tabs Today / Team / Performance / System activate in-page (`data-operations-tab-target`) or via `?hub_tab=` deep link
- Automation Health, Automation, Webhook Explorer deep-link from hub nav
- Sidebar entries unchanged

**Out of scope (H3C+)**

- Collapsing Operations Hub sidebar children
- Embedding automation pages into Control Center
- KPI or service consolidation

### H3C-1 scope (implemented)

**In scope**

- Single primary Operations hub tab bar on Control Center (merged H3B hub nav + inner dashboard tabs)
- Today / Team / Performance / System remain Bootstrap lazy tabs with existing IDs, live groups, and polling
- Automation Health, Automation, Webhook Explorer remain deep links in the merged bar
- Child pages (Automation Health, Automation, Webhook Explorer) keep standalone hub nav with deep links
- `?hub_tab=` deep links unchanged (`activateOperationsHubTabFromQuery` in `operations-dashboard.js`)

**Out of scope (H3C-2+)**

- Automation native tab embed
- Sidebar collapse for Operations children
- Route aliases
- Controller, service, permission, polling, caching, or realtime changes

### H3C-2A scope (implemented)

**In scope**

- Native **Automation** tab on Operations Control Center (`?hub_tab=automation`)
- Lazy-load Automation Health by fetching existing `admin.operations.automation-health` embed fragment (`data-automation-health-embed`)
- Shared `dashboard-body` partial for standalone page + embed (no duplicated business logic)
- Execution detail drawer on Control Center; filters/pagination remain in-tab via embed navigation
- Hub nav: Control Center shows Automation tab; child pages keep Automation Health deep link
- Permission-gated: `automation-operations.view` required for tab, pane, drawer, and embed URL

**Out of scope (H3C-2B+)**

- Embedding Automation Operations into Automation tab
- Sidebar collapse
- Route aliases / redirects
- Controller, service, query, API, permission, polling, caching, or realtime changes

### H3C-2B scope (implemented)

**In scope**

- **Health** / **Pipeline** secondary nav inside Automation tab (default: Health)
- Pipeline sub-view lazy-loads `admin.automation.index` embed fragment (`data-automation-pipeline-embed`)
- Shared `admin/automation/partials/dashboard-body.blade.php` for standalone + embed
- Deep link: `?hub_tab=automation&automation_view=pipeline`
- Health sub-view unchanged (H3C-2A behavior)
- Sidebar unchanged; standalone `/admin/automation` and `/admin/operations/automation-health` preserved

**Out of scope (H3C-3+)**

- Sidebar collapse for Automation Health / Automation
- Route aliases / redirects
- Controller, service, query, API, permission, polling, caching, or realtime changes

### H3C-3 scope (implemented)

**In scope**

- Hide **Automation Health** and **Automation** sidebar items for `admin`, `operations_admin`, `superadmin`
- **Operations** + **Webhook Explorer** remain the Operations Hub sidebar entries
- Operations sidebar link active on automation standalone routes (`admin.operations.automation-health*`, `admin.automation.*`)
- Hub nav on child pages: single **Automation** deep link → Control Center (`?hub_tab=automation` / `automation_view=pipeline`)
- Removed duplicate Automation pipeline link from Control Center hub nav
- All legacy routes continue to serve standalone pages (no redirects — least disruptive)

**Out of scope (H3C-4+)**

- Webhook Explorer sidebar hide
- Route redirects
- Controller, service, or backend changes

### H3 direction — Operations (H3C plan approved for implementation)

See [super-admin-h3c-operations-consolidation.md](super-admin-h3c-operations-consolidation.md).

| Area | Merge target | Pattern |
|---|---|---|
| Automation Health + Automation Operations | Operations Control Center **Automation tab** (Health + Pipeline sub-views) | Embed on `admin.operations.index`; standalone routes → aliases |
| Team Performance, Holidays, Leave queue | Workforce360 | Inline tabs on `workforce.index`; routes remain |
| Audit log detail | Administration | Drawer from Audit list |
| Administration sidebar children | Administration Home | Hide nav items; cards + in-page links remain |
| Operator-sidebar Workforce duplicate | — | Remove **nav item only** for admin roles; `workforce.index` unchanged |

---

## Feature Preservation Register

**Legend**

- **Freq:** High = hub home / live-polled · Medium = sidebar or regular drill-down · Low = deep link / forensic · Placeholder = UI stub
- **Removal:** **No** = never without approval · **Future candidate** = may consolidate presentation only · **Approved** = none today

### Register A — Mission Control (`admin.platform.index`)

| ID | Feature / KPI / widget | Current location(s) | Business purpose | Primary users | Freq | Owner hub | Destination | Section / tab | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| MC-01 | Executive: Open Cases | Platform → Executive Snapshot | Active case volume | Owner, ops lead | High | Mission Control | `admin.platform.index` | Executive Snapshot | Keep | No |
| MC-02 | Executive: Critical Cases |同上 | High-priority case attention | Owner, ops lead | High | Mission Control |同上 | Executive Snapshot | Keep | No |
| MC-03 | Executive: Refund Queue |同上 | Pending refund workload | Owner, finance ops | Medium | Mission Control |同上 | Executive Snapshot | Keep | No |
| MC-04 | Executive: Active Agents |同上 | Staffing at a glance | Owner, ops lead | High | Mission Control |同上 | Executive Snapshot | Keep; H4 consume shared presence DTO | No |
| MC-05 | Executive: Customers Waiting |同上 | Customer wait backlog | Owner, ops lead | High | Mission Control |同上 | Executive Snapshot | Keep | No |
| MC-06 | Executive: Orders Today |同上 | Daily order intake | Owner | Medium | Mission Control |同上 | Executive Snapshot | Keep | No |
| MC-07 | Executive: Resolved Today |同上 | Daily throughput | Owner | Medium | Mission Control |同上 | Executive Snapshot | Keep | No |
| MC-08 | Executive: Appointments Today |同上 | Daily appointment load | Owner, ops lead | High | Mission Control |同上 | Executive Snapshot | Keep; H4 import from Support Intelligence | No |
| MC-09 | Platform Health card (aggregate) | Platform → Platform Health | Infra health rollup | Owner, superadmin | High | Mission Control |同上 | Platform Health | Keep | No |
| MC-10 | Health: Scheduler | Platform Health card | Cron/scheduler probe | Superadmin | Medium | Mission Control |同上 | Platform Health | Keep | No |
| MC-11 | Health: Presence | Platform Health card | Presence engine probe | Superadmin | Medium | Mission Control |同上 | Platform Health | Keep | No |
| MC-12 | Health: Queue | Platform Health card | Job queue probe | Superadmin | Medium | Mission Control |同上 | Platform Health | Keep | No |
| MC-13 | Health: Automation (infra) | Platform Health card | Automation runtime probe | Superadmin | Medium | Mission Control |同上 | Platform Health | Keep | No |
| MC-14 | Health: Database | Platform Health card | DB connectivity | Superadmin | Low | Mission Control |同上 | Platform Health | Keep | No |
| MC-15 | Health: Cache | Platform Health card | Cache connectivity | Superadmin | Low | Mission Control |同上 | Platform Health | Keep | No |
| MC-16 | Health: Storage | Platform Health card | Storage writable | Superadmin | Low | Mission Control |同上 | Platform Health | Keep | No |
| MC-17 | Per-card refresh | Platform cards | Independent card reload | Superadmin | Medium | Mission Control | `admin.platform.cards.show` | Per-card API | Keep | No |
| MC-18 | Placeholder: Business Operations | Platform section card | Future ops rollup pointer | Owner | Placeholder | Operations | Deep link → `admin.operations.index` | H4 replace placeholder | Relocate | No |
| MC-19 | Placeholder: Customer Operations | Platform section card | Future customer ops pointer | Owner | Placeholder | Operations | Deep link → operator Dashboard / Cases | H4 replace placeholder | Relocate | No |
| MC-20 | Placeholder: Workforce | Platform section card | Future workforce pointer | Owner | Placeholder | Workforce | Deep link → `workforce.index` | H4 replace placeholder | Relocate | No |
| MC-21 | Placeholder: Communications | Platform section card | Future comms pointer | Owner | Placeholder | Operations | Deep link → Operations System tab | H4 replace placeholder | Relocate | No |
| MC-22 | Placeholder: Finance | Platform section card | Future finance pointer | Owner | Placeholder | Operations | Deep link → Cashfree areas | H4 replace placeholder | Relocate | No |
| MC-23 | Placeholder: Automation | Platform section card | Future automation pointer | Owner | Placeholder | Operations | Deep link → Automation Health | H4 replace placeholder | Relocate | No |
| MC-24 | Placeholder: System | Platform section card | Future system pointer | Owner | Placeholder | Administration | Deep link → System Settings | H4 replace placeholder | Relocate | No |
| MC-25 | Generated-at timestamp | Platform header | Snapshot freshness | All | High | Mission Control | Header | Keep | No |

### Register B — Operations Control Center (`admin.operations.index`)

| ID | Feature / KPI / widget | Current location(s) | Business purpose | Primary users | Freq | Owner hub | Destination | Section / tab | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| OP-01 | Live polling (`admin.operations.live`) | Control Center root | Real-time partial updates | Ops admin | High | Operations | Hub home | Global | Keep | No |
| OP-02 | Lazy tab loading | Today/Team/Performance/System tabs | Performance on first paint | Ops admin | High | Operations | Hub home | Tabs | Keep | No |
| OP-03 | Critical Alerts feed | Above-fold bento | Immediate operational risks | Ops admin | High | Operations | Hub home | Critical Alerts | Keep | No |
| OP-04 | Alert: Cashfree paid missing desk order | Critical Alerts | Payment integrity | Ops admin | High | Operations | Hub home → health drill | Critical Alerts | Keep | No |
| OP-05 | Alert: Cashfree webhook failures | Critical Alerts | Webhook recovery | Ops admin | High | Operations | Hub home → health drill | Critical Alerts | Keep | No |
| OP-06 | Alert: RadiumBox sync failures | Critical Alerts | Integration recovery | Ops admin | High | Operations | Hub home → health drill | Critical Alerts | Keep | No |
| OP-07 | Alert: Overdue appointments | Critical Alerts | Support SLA | Ops admin | High | Operations | Hub home → Today tab | Critical Alerts | Keep | No |
| OP-08 | Alert: IRA high risks | Critical Alerts | AI-detected risks | Ops admin | Medium | Operations | Hub home → Today tab | Critical Alerts | Keep | No |
| OP-09 | Bento: Today's Operations Health | Overview cards | Support + queue posture | Ops admin | High | Operations | Hub home | Bento | Keep | No |
| OP-10 | Bento KPI: Needs action | Overview cards | Action-required queue | Ops admin | High | Operations | Hub home | Bento | Keep | No |
| OP-11 | Bento KPI: SLA risks | Overview cards | SLA warning/overdue | Ops admin | High | Operations | Hub home | Bento | Keep | No |
| OP-12 | Bento KPI: Scheduled today | Overview cards | Appointment schedule | Ops admin | High | Operations | Hub home | Bento | Keep | No |
| OP-13 | Bento KPI: Missed/overdue today | Overview cards | Missed appointments | Ops admin | High | Operations | Hub home | Bento | Keep | No |
| OP-14 | Bento KPI: Hardware SLA risk | Overview cards | Hardware SLA | Ops admin | Medium | Operations | Hub home | Bento | Keep | No |
| OP-15 | Bento: IVR Health | Overview cards | Call center health | Ops admin | High | Operations | Hub home → Performance tab | Bento | Keep | No |
| OP-16 | Bento: Team Load | Overview cards | Capacity hotspot | Ops admin | High | Operations | Hub home → Team tab | Bento | Keep | No |
| OP-17 | IRA Briefing compact | Bento IRA cell | AI operational briefing | Ops admin | High | Operations | Hub home | IRA compact | Keep | No |
| OP-18 | IRA Full Analysis modal | Modal via JS | Deep IRA analysis | Ops admin | Medium | Operations | Hub home | Modal | Keep | No |
| OP-19 | IRA feedback buttons | IRA sections | Improve IRA signals | Ops admin | Low | Operations | IRA modal / briefing | Feedback | Keep | No |
| OP-20 | IRA Advisor insights | Loaded in full analysis | Advisory recommendations | Ops admin | Medium | Operations | IRA modal | Advisor section | Keep | No |
| OP-21 | Health status compact row | Below bento | Cashfree/RadiumBox/Telegram/integrations | Ops admin | High | Operations | Hub home | Health row | Keep | No |
| OP-22 | Health expandable lazy detail | Health row triggers | Drill-down without full page load | Ops admin | Medium | Operations | Hub home | Progressive disclosure | Keep | No |
| OP-23 | Tab: Today | Tab bar | Support intelligence | Ops admin | High | Operations | Hub home | Today | Keep | No |
| OP-24 | Support Intelligence summary | Today tab | Daily support KPIs | Ops admin | High | Operations | Today tab | Support Intelligence | Keep | No |
| OP-25 | Support Intelligence details collapse | Today tab | Serial/unassigned drill-down | Ops admin | Medium | Operations | Today tab | Progressive disclosure | Keep | No |
| OP-26 | Team workload cards | Today tab (details) | Per-agent load | Ops admin | Medium | Operations | Today tab | Workload | Keep | No |
| OP-27 | Tab: Team | Tab bar | Presence + Telegram | Ops admin | High | Operations | Hub home | Team | Keep | No |
| OP-28 | Team Presence (on duty) | Team tab | Who is working | Ops admin | High | Operations | Team tab | Team Presence | Keep | No |
| OP-29 | Team Presence (unavailable) | Team tab | Expected but away | Ops admin | High | Operations | Team tab | Team Presence | Keep | No |
| OP-30 | Team availability row expand | Team tab | Per-member detail | Ops admin | Medium | Operations | Team tab | Rows | Keep | No |
| OP-31 | Team Telegram Status | Team tab | Telegram connectivity | Ops admin | Medium | Operations | Team tab | Telegram | Keep | No |
| OP-32 | Tab: Performance | Tab bar | IVR + metrics + quality | Ops admin | High | Operations | Hub home | Performance | Keep | No |
| OP-33 | IVR Health detail | Performance tab | Call stats today | Ops admin | Medium | Operations | Performance tab | IVR Health | Keep | No |
| OP-34 | IVR Agent Performance | Performance tab | Per-agent call metrics | Ops admin | Medium | Operations | Performance tab | IVR Agents | Keep | No |
| OP-35 | IVR Missed Calls list | Performance tab | Recovery list | Ops admin | Medium | Operations | Performance tab | Missed Calls | Keep | No |
| OP-36 | Notification Metrics | Performance tab | Channel dispatch stats | Ops admin | Medium | Operations | Performance tab | Notifications | Keep | No |
| OP-37 | Automation Metrics | Performance tab | Execution counts today | Ops admin | High | Operations | Performance tab | Automation | Keep; H4 shared read facade | No |
| OP-38 | Queue Metrics | Performance tab | Job queue depth | Ops admin | Medium | Operations | Performance tab | Queue | Keep | No |
| OP-39 | RadiumBox Health (full) | Performance tab | Sync pipeline health | Ops admin | High | Operations | Performance tab | RadiumBox | Keep | No |
| OP-40 | RadiumBox batch recovery action | Performance tab | Recover failed syncs | Ops admin | Medium | Operations | Performance tab | Quick action | Keep | No |
| OP-41 | Cashfree Health (full) | Performance tab | Payment/webhook health | Ops admin | High | Operations | Performance tab | Cashfree | Keep | No |
| OP-42 | Cashfree device enrichment quality | Performance tab | Enrichment QA | Ops admin | Low | Operations | Performance tab | Cashfree quality | Keep | No |
| OP-43 | Missing serial automation quality | Performance tab | Serial automation QA | Ops admin | Low | Operations | Performance tab | Serial quality | Keep | No |
| OP-44 | Tab: System | Tab bar | Runtime + feeds | Ops admin | High | Operations | Hub home | System | Keep | No |
| OP-45 | System Health (8 components) | System tab | Runtime comms health | Ops admin | High | Operations | System tab | System Health | Keep | No |
| OP-46 | Integration Health cards | System tab | Third-party config health | Ops admin | High | Operations | System tab | Integrations | Keep | No |
| OP-47 | Recent Notification Failures | System tab | Delivery failures | Ops admin | Medium | Operations | System tab | Activity feed | Keep | No |
| OP-48 | Recent Automation Activity | System tab | Execution feed (summary) | Ops admin | Medium | Operations | System tab | Activity feed | Keep; H3 link to Automation Health tab | No |
| OP-49 | Recent IRA Messages | System tab | IRA message log | Ops admin | Low | Operations | System tab | Activity feed | Keep | No |
| OP-50 | Bento → tab shortcuts | Overview cards | In-page navigation | Ops admin | High | Operations | Hub home | Shortcuts | Keep | No |
| OP-51 | Telegram broadcast API | `admin.operations.telegram.broadcast` | Team broadcast (API/CLI) | Ops admin | Low | Operations | Keep route; H3 expose UI if hidden | Quick action | Keep | No |

### Register C — Automation Health (`admin.operations.automation-health`)

| ID | Feature | Current location | Business purpose | Primary users | Freq | Owner hub | Destination | Section | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| AH-01 | Overview KPI strip (7 cards) | Automation Health page | Ledger health at a glance | Ops admin | High | Operations | H3: Operations tab **or** keep standalone | Overview | Merge tab; **route alias** | No |
| AH-02 | Breakdown by automation type | Automation Health | Type-level stats | Ops admin | Medium | Operations | Same | Breakdown | Merge tab | No |
| AH-03 | Activity table + filters | Automation Health | Searchable execution log | Ops admin | High | Operations | Same | Activity + filters | Keep filters | No |
| AH-04 | Filter: search | Activity filters | Find executions | Ops admin | Medium | Operations | Same | Filters | Keep | No |
| AH-05 | Filter: automation type | Activity filters | Type filter | Ops admin | Medium | Operations | Same | Filters | Keep | No |
| AH-06 | Filter: status | Activity filters | Status filter | Ops admin | Medium | Operations | Same | Filters | Keep | No |
| AH-07 | Filter: date | Activity filters | Date filter | Ops admin | Medium | Operations | Same | Filters | Keep | No |
| AH-08 | Recent failures list | Automation Health | Failure triage | Ops admin | High | Operations | Same | Failures | Keep | No |
| AH-09 | Execution detail drawer | Offcanvas | Execution forensics | Ops admin | High | Operations | Same | Drawer | Keep | No |
| AH-10 | Execution show API | `automation-health.executions.show` | Drawer data | Ops admin | High | Operations | API | Drawer | Keep | No |

### Register D — Automation Operations (`admin.automation.index`)

| ID | Feature | Current location | Business purpose | Primary users | Freq | Owner hub | Destination | Section | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| AU-01 | Automation Health KPI strip (8 counts) | Automation page | Case pipeline queues | Ops admin | High | Operations | H3: Operations tab | Health counts | Merge tab | No |
| AU-02 | Action Queue: Waiting for Customer Serial | Automation page | Serial wait triage | Ops admin | High | Operations | H3 tab | Action Queues | Keep | No |
| AU-03 | Action Queue: Duplicate Serial Conflicts | Automation page | Identity conflicts | Ops admin | Medium | Operations | H3 tab | Action Queues | Keep | No |
| AU-04 | Action Queue: RadiumBox Not Found | Automation page | Enrichment gaps | Ops admin | Medium | Operations | H3 tab | Action Queues | Keep | No |
| AU-05 | Recent Automation Events | Automation page | Case automation audit trail | Ops admin | Medium | Operations | H3 tab | Recent Events | Keep | No |
| AU-06 | Repair Summary KPIs | Automation page | Identity repair stats | Ops admin | Medium | Operations | H3 tab | Repair Summary | Keep | No |
| AU-07 | Validation Summary (product/rule/category) | Automation page | Validation failure breakdown | Ops admin | Medium | Operations | H3 tab | Validation | Keep | No |

### Register E — Webhook Explorer (`cashfree.webhook-explorer.*`)

| ID | Feature | Current location | Business purpose | Primary users | Freq | Owner hub | Destination | Section | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| WH-01 | Signature verification status | Webhook Explorer index | Security posture | Ops admin | Medium | Operations | Standalone page | Security card | Keep | No |
| WH-02 | Webhook ID search | Webhook Explorer | Find payload | Ops admin | Medium | Operations | Standalone | Search filter | Keep | No |
| WH-03 | Webhook log table | Webhook Explorer | Inspect history | Ops admin | Medium | Operations | Standalone | Table | Keep | No |
| WH-04 | Webhook detail show | `webhook-explorer.show` | Payload forensics | Ops admin | Low | Operations | Standalone | Detail page | Keep | No |

### Register F — Workforce (`workforce.index`, `workforce.show`, related)

| ID | Feature | Current location | Business purpose | Primary users | Freq | Owner hub | Destination | Section / tab | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| WF-01 | Team hero metrics | Workforce360 team | Team health summary | Ops lead | High | Workforce | `workforce.index` | Hero | Keep | No |
| WF-02 | Capacity strip | Workforce360 team | Available/busy/offline/on leave | Ops lead | High | Workforce | Hub home | Capacity | Keep | No |
| WF-03 | Member list | Workforce360 team | Team roster + status | Ops lead | High | Workforce | Overview tab | Member list | Keep | No |
| WF-04 | Tab: Overview | Team page | Default team view | Ops lead | High | Workforce | Hub home | Overview | Keep | No |
| WF-05 | Tab: Timeline (placeholder) | Team page | Future timeline | Ops lead | Placeholder | Workforce | Hub home | Timeline | Keep stub | No |
| WF-06 | Tab: Leave Queue → external | Team tabs | Admin leave review | Ops lead | Medium | Workforce | Hub nav → Leave (H3A) | Leave | **Deep link (H3A)** | No |
| WF-07 | Tab: Holidays → external | Team tabs | Holiday management | Ops lead | Medium | Workforce | Hub nav → Holidays (H3A) | Holidays | **Deep link (H3A)** | No |
| WF-08 | Member 360 (`workforce.show`) | Member page | Individual drill-down | Ops lead | High | Workforce | Standalone drill-down | Member tabs | Keep | No |
| WF-09 | Member tabs (overview/schedule/attendance/leave/workload) | Member page | Self-service + admin view | Agent, ops lead | High | Workforce | Member page | Tabs | Keep | No |
| WF-10 | Member: block reasons | Member overview | Explain non-assignment | Ops lead | Medium | Workforce | Member overview | Card | Keep | No |
| WF-11 | Member: quick actions | Member overview | Leave request, performance link | Agent | Medium | Workforce | Member overview | Quick actions | Keep | No |
| WF-12 | Team Performance page | `admin.workforce.performance.index` | Period team metrics | Ops lead | Medium | Workforce | Hub nav → Performance (H3A); embed H3B | Performance | **Deep link (H3A)** | No |
| WF-13 | Performance period filter | Team Performance | Date range selection | Ops lead | Medium | Workforce | Performance tab | Filter | Keep | No |
| WF-14 | IRA performance insights | Team Performance | Coaching insights | Ops lead | Medium | Workforce | Performance tab | Insights | Keep | No |
| WF-15 | Per-member performance cards | Team Performance | Individual scorecards | Ops lead | Medium | Workforce | Performance tab | Cards | Keep | No |
| WF-16 | Company Holidays CRUD | `admin.workforce.holidays.*` | Calendar blocking | Ops lead | Low | Workforce | H3: Holidays tab | Form + list | Convert tab | No |
| WF-17 | Leave Requests index/create/show | `leave-requests.*` | Leave workflow | Agent, ops lead | Medium | Workforce | H3: Leave tab | Queue + forms | Convert tab | No |
| WF-18 | Leave approve/reject actions | Leave show | Admin approval | Ops lead | Medium | Workforce | Leave workflow | Actions | Keep | No |
| WF-19 | My Workforce (`my-workforce.index`) | Self route | Agent self view | Agent | High | Workforce *(self)* | Out of Super Admin hubs | Member 360 | Keep as-is | No |
| WF-20 | Your Performance (`my-performance.index`) | Self route | Agent self metrics | Agent | Medium | Workforce *(self)* | Out of Super Admin hubs | Performance | Keep as-is | No |
| WF-21 | Duplicate Workforce nav (operator section) | Sidebar | Legacy entry | Admin | Medium | Workforce | Removed for admin roles (H3A) | — | **Hide nav (H3A)** | No |

### Register G — Administration (current + H2 Administration Home)

| ID | Feature | Current location | Business purpose | Primary users | Freq | Owner hub | Destination | Section | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-01 | **Administration Home** | `admin.administration.index` | Unified admin entry | Admin, superadmin | High | Administration | `admin.administration.index` | Card grid | **Keep (H2 shipped)** | No |
| AD-02 | Users index + filters | `users.index` | Account directory | Admin | High | Administration | Users page | Filters + table | Keep | No |
| AD-03 | User create | `users.create` | Onboarding | Admin | Medium | Administration | Users | Form | Keep | No |
| AD-04 | User edit + roles | `users.edit` | Access management | Admin | High | Administration | Users | Form + roles | Keep | No |
| AD-05 | Access permissions partial | User form | Fine-grained permissions | Superadmin | Medium | Administration | Users | Permissions | Keep | No |
| AD-06 | Work schedule on user edit | User edit | Schedule setup | Admin | Medium | Workforce *(data)* / Administration *(UI)* | Users edit | Work schedule | Keep | No |
| AD-07 | Reset password modal | User edit | Account recovery | Admin | Low | Administration | Users | Modal | Keep | No |
| AD-08 | User status toggle | Users table | Activate/deactivate | Admin | Medium | Administration | Users | Actions | Keep | No |
| AD-09 | User delete | User edit | Remove account | Superadmin | Low | Administration | Users | Action | Keep | No |
| AD-10 | System Settings form | `admin.system-settings.index` | Feature flags / toggles | Admin | Medium | Administration | System Settings | Category cards | Keep | No |
| AD-11 | Realtime settings card | System Settings | Hybrid realtime config | Superadmin | Medium | Administration | System Settings | Realtime accordion | Keep | No |
| AD-12 | Realtime test / force-reconnect / reset | System Settings actions | Ops troubleshooting | Superadmin | Low | Administration | System Settings | Quick actions | Keep | No |
| AD-13 | Performance profile card | System Settings | Poll interval presets | Superadmin | Medium | Administration | System Settings | Performance accordion | Keep | No |
| AD-14 | Application Settings (8 tabs) | `settings.index` | App configuration | Superadmin | Medium | Administration | Application Settings | Tab nav | Keep | No |
| AD-15 | Settings: General | Application Settings | Core config | Superadmin | Medium | Administration | App Settings | General tab | Keep | No |
| AD-16 | Settings: Service Cases (products) | Application Settings | Product config | Superadmin | Low | Administration | App Settings | Products tab | Keep | No |
| AD-17 | Settings: Models + aliases | Application Settings | Device catalog | Superadmin | Low | Administration | App Settings | Models tab | Keep | No |
| AD-18 | Settings: Sources | Application Settings | Intake sources | Superadmin | Low | Administration | App Settings | Sources tab | Keep | No |
| AD-19 | Settings: Assignment | Application Settings | Routing rules | Superadmin | Medium | Administration | App Settings | Assignment tab | Keep | No |
| AD-20 | Settings: Notifications | Application Settings | Notification policy | Superadmin | Medium | Administration | App Settings | Notifications tab | Keep | No |
| AD-21 | Settings: SLA | Application Settings | SLA thresholds | Superadmin | Medium | Administration | App Settings | SLA tab | Keep | No |
| AD-22 | Settings: Search | Application Settings | Search config | Superadmin | Low | Administration | App Settings | Search tab | Keep | No |
| AD-23 | Audit Logs index + filters | `audit-logs.index` | Compliance review | Admin | Medium | Administration | Audit Logs | Search + filters | Keep | No |
| AD-24 | Audit log show | `audit-logs.show` | Event detail | Admin | Low | Administration | H3: drawer | Detail | Convert drawer | No |
| AD-25 | Roles (no standalone page) | User create/edit | Role assignment | Admin | High | Administration | Users | Roles field | Keep; card on Administration Home | No |
| AD-26 | Integrations placeholder | — | Future integration hub | Superadmin | — | Administration | Administration Home card → System Settings | Future | Add H2 placeholder | No |

### Register H — Global shell (adjacent; preserve; not hub consolidation targets)

| ID | Feature | Location | Business purpose | Primary users | Freq | Owner | Action | Removal |
|---|---|---|---|---|---|---|---|---|
| GL-01 | Universal Search (navbar + sidebar) | `search.index` | Cross-entity lookup | All | High | Platform | Keep outside hubs | No |
| GL-02 | Notification bell + dropdown | Navbar | Alert delivery | All | High | Platform | Keep | No |
| GL-03 | Notifications index/show | `notifications.*` | Notification history | All | Medium | Platform | Keep | No |
| GL-04 | Operator Dashboard | `dashboard` | Day-to-day queue work | Agents | High | Operations *(operator)* | Not a Super Admin hub target | No |
| GL-05 | Profile / availability | `profile.*` | Self settings | All | Medium | Platform | Keep | No |

---

## KPI Ownership Matrix

> **H4-1 complete:** Full inventory, duplicate analysis, read-model proposal, cache/poll/realtime ownership, dependency graph, and migration order are in **[super-admin-h4-1-kpi-read-model-inventory.md](super-admin-h4-1-kpi-read-model-inventory.md)**. The matrix below remains the quick-reference summary.

**Goal:** Every KPI has exactly one authoritative owner service. Other dashboards **consume** the same data via read APIs/DTOs — no independent recomputation long-term.

**Removal column:** All rows = **No** unless marked **Future candidate** (presentation merge only).

### Executive / business rollups

| KPI | Source-of-truth service/class | Primary owner page | Secondary consumers | Duplicated today? | Long-term owner | Cache | Consolidation | Recommended action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Open Cases | `ExecutiveMetricsContextBuilder` | Mission Control | Operations bento (queue) | **Yes** | `ExecutiveMetricsService` | 60s `ExecutiveMetricsCache` | H4: Operations consumes executive DTO | Keep MC primary; Ops consumes | No |
| Critical Cases | `ExecutiveMetricsContextBuilder` | Mission Control | — | No | `ExecutiveMetricsService` | 60s | — | Keep | No |
| Refund Queue | `ExecutiveMetricsContextBuilder` | Mission Control | — | No | `ExecutiveMetricsService` | 60s | — | Keep | No |
| Active Agents | `ExecutiveMetricsContextBuilder` (`WorkSession`) | Mission Control | Ops Team Load, Workforce | **Yes** (different defs) | `PresenceEngineService` + DTO | 60s MC; live elsewhere | H4: define canonical “active” metric | Keep all; unify source | No |
| Customers Waiting | `ExecutiveMetricsContextBuilder` | Mission Control | Operations Today | **Yes** | `ExecutiveMetricsService` | 60s | Ops imports DTO | Keep | No |
| Orders Today | `ExecutiveMetricsContextBuilder` | Mission Control | — | No | `ExecutiveMetricsService` | 60s | — | Keep | No |
| Resolved Today | `ExecutiveMetricsContextBuilder` | Mission Control | — | No | `ExecutiveMetricsService` | 60s | — | Keep | No |
| Appointments Today | `ExecutiveMetricsContextBuilder` | Mission Control | Operations bento + Today | **Yes** | `OperationsSupportIntelligenceService` | 60s MC; 30s ops | Support intelligence owns; MC imports | Keep both surfaces | No |
| Needs action / action required | `DashboardSnapshot` via `OperationsSupportIntelligenceService` | Operations Control Center | Mission Control (conceptual) | **Yes** | `DashboardSnapshot` / queue classifier | 30s ops bundle | MC deep-links; no local query | Keep Ops primary | No |
| SLA risks (service/hardware) | `DashboardSnapshot` SLA counts | Operations | Mission Control Critical (partial) | Partial | `DashboardSnapshot` | 30s | Align definitions H4 | Keep | No |
| Scheduled / completed / pending / missed today | `OperationsSupportIntelligenceService` | Operations Today tab | Operations bento | No | `OperationsSupportIntelligenceService` | 30s | MC imports `scheduledToday` | Keep | No |

### Platform / system health

| KPI | Source-of-truth | Primary owner page | Secondary consumers | Duplicated? | Long-term owner | Cache | Consolidation | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Platform Health aggregate | `PlatformHealthRegistry` | Mission Control | — | No | `PlatformHealthRegistry` | Per-card refresh | — | Keep | No |
| Scheduler / DB / Cache / Storage / Queue probes | `Platform\Health\*Provider` | Mission Control | Ops System Health (partial) | Partial | `PlatformHealthRegistry` (infra) | On refresh | Scope split documented | Keep both scopes | No |
| Automation infra probe | `Platform\Health\AutomationHealthProvider` | Mission Control | — | Partial vs ledger | Platform probe | On refresh | Not merged with ledger | Keep | No |
| System Health 8 components | `OperationsSystemHealthService` | Operations System tab | — | No | `OperationsSystemHealthService` | 30s snapshot | — | Keep | No |
| Integration health cards | `OperationsIntegrationHealthService` | Operations System tab | Administration *(future chips)* | Future | `OperationsIntegrationHealthService` | 30s | Admin reads status only | Keep | No |

### Automation execution ledger

| KPI | Source-of-truth | Primary owner page | Secondary consumers | Duplicated? | Long-term owner | Cache | Consolidation | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Executions today | `AutomationExecutionReadModel` → `AutomationHealthService` | Automation Health | Operations Performance + activity summary | Resolved (H4-3) | `AutomationExecutionReadModel` | 60s Health aggregation | Single facade | Keep both pages; shared source | No |
| Failures today |同上 | Automation Health | Operations + advisor | Resolved (H4-3) |同上 | 60s shared |同上 | Keep | No |
| Pending executions |同上 | Automation Health | Activity summary | Resolved (H4-3) |同上 | 60s shared |同上 | Keep | No |
| Avg execution time |同上 | Automation Health | Operations Performance | Resolved (H4-3) |同上 | 60s shared |同上 | Keep | No |
| Last success / failed run | `AutomationHealthService` | Automation Health | — | No | `AutomationHealthService` | 60s aggregation cache | — | Keep | No |
| Breakdown by type | `AutomationHealthService` | Automation Health | — | No | `AutomationHealthService` | 60s aggregation cache | — | Keep | No |
| Activity table + filters | `AutomationHealthService` | Automation Health | Ops recent activity (subset) | Partial | `AutomationHealthService` | Paginated (uncached) | Ops shows summary + link | Keep; H3 tab merge | No |
| Recent failures list | `AutomationHealthService` | Automation Health | — | No | `AutomationHealthService` | 60s aggregation cache | — | Keep | No |

### Service case automation pipeline

| KPI | Source-of-truth | Primary owner page | Secondary consumers | Duplicated? | Long-term owner | Cache | Consolidation | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Automation pending / serial wait / validation failed / etc. | `ServiceCaseAutomationHealthService` | Automation Operations | Operations quality partials | Partial | `ServiceCaseAutomationHealthService` | 60s `AutomationOperationsSnapshotService` | — | Keep; H3 tab | No |
| Cashfree webhook/outbox counts | `CashfreeWebhookReliabilityMetrics` | Automation Operations | Ops Cashfree health | **Yes** | `CashfreeWebhookReliabilityMetrics` | Counter cache | Single DTO | Keep | No |
| Repair statistics | `OrderIdentityRepairService` | Automation Operations | — | No | `OrderIdentityRepairService` | 60s snapshot | — | Keep | No |
| Validation by product/rule/category | `AutomationOperationsValidationCollector` | Automation Operations | — | No | Same | 60s snapshot | — | Keep | No |

### Integrations / payments

| KPI | Source-of-truth | Primary owner page | Secondary consumers | Duplicated? | Long-term owner | Cache | Consolidation | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Cashfree health widget | `OperationsCashfreeHealthService` | Operations (alerts + Performance) | Webhook Explorer | Partial | `CashfreePaymentIntegrityService` | 30s | Shared integrity service | Keep all surfaces | No |
| Paid without desk order | `CashfreePaymentIntegrityService` | Operations Critical Alerts | Automation counts | **Yes** | `CashfreePaymentIntegrityService` | 30s | Single source | Keep | No |
| Active failed webhooks |同上 | Operations + Explorer | Automation | **Yes** |同上 | 30s |同上 | Keep | No |
| RadiumBox pending/failed/success% | `OperationsRadiumBoxHealthService` | Operations | Automation `radiumbox_pending` | Partial | `RadiumBoxIntegrationHealthProbe` | 30s | — | Keep | No |
| IVR calls / answered% / missed% | `BonvoiceAnalyticsService` | Operations Performance | Operations bento | No | `BonvoiceAnalyticsService` | 60s | — | Keep | No |
| IVR agent performance | `BonvoiceAnalyticsService` | Operations Performance | Team Performance (partial) | Partial | `BonvoiceAnalyticsService` | 60s | Link don't recompute | Keep | No |

### Workforce / team

| KPI | Source-of-truth | Primary owner page | Secondary consumers | Duplicated? | Long-term owner | Cache | Consolidation | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Available / busy / offline / on leave | `Workforce360Service` | Workforce360 | Operations Team bento | **Yes** | `Workforce360Service` | Per request | Ops consumes overview DTO | Keep WF primary | No |
| Pending leave count | `LeaveRequest` via `Workforce360Service` | Workforce360 | — | No | `LeaveRequestService` | Per request | — | Keep | No |
| Late login / session timeout / attendance exceptions | `Workforce360Service` | Workforce360 | Team Performance | Partial | `AttendanceRegisterService` | Per request | — | Keep | No |
| On duty / unavailable members | `TeamAvailabilityOverviewService` | Operations Team tab | Workforce360 list | **Yes** | `TeamAvailabilityOverviewService` | Shared | Already shared service | Keep both views | No |
| Team member performance metrics | `TeamPerformanceMetricsService` | Team Performance | Member tab, My Performance | No | `TeamPerformanceMetricsService` | Per period | — | Keep; H3 WF tab | No |
| Notification metrics (sent/failed/skipped) | `OperationsAuditAggregator` | Operations Performance | — | No | `OperationsAuditAggregator` | Audit batch | — | Keep | No |
| Queue metrics (jobs pending/failed) | `OperationsQueueMetricsService` | Operations Performance | Mission Control queue probe | **Yes** | `QueueMetricsService` | 30s | Shared snapshot | Keep | No |

### Administration *(future KPIs on Administration Home)*

| KPI | Source-of-truth | Primary owner page | Secondary consumers | Duplicated? | Long-term owner | Cache | Consolidation | Action | Removal |
|---|---|---|---|---|---|---|---|---|---|
| Active/inactive user counts | *Not displayed* | Administration Home *(H3+)* | — | No | `UserManagementService` | 5m admin | New admin aggregator only | Hide until H3+ | No |
| Audit events (24h) | *Not displayed* | Administration Home *(H3+)* | — | No | Audit query wrapper | 5m | — | Hide until H3+ | No |
| Integration toggle summary | `SystemSettingsService` | System Settings | Ops integration health | No | `SystemSettingsService` | Config cache | Admin chips read-only | Keep | No |

---

## Implementation order (H2 onward)

1. **Document + register** (this update) — Feature Preservation Register is authoritative.
2. **Hub card-grid partial** — reusable; Administration Home first consumer only.
3. **Administration Home** — `admin.administration.index`; static cards; permission-gated links.
4. **Sidebar** — Administration primary → Administration Home; keep secondary links.
5. **Feature tests** — card visibility; linked routes unchanged; no regressions.
6. **H3 planning gate** — no tab/drawer merge until each Register row has signed destination.
7. **H4-1** ✅ — KPI inventory + ownership matrix + read model proposal (planning only).
8. **H4-2** ✅ — `AutomationHealthService` 60s shared aggregation cache (overview/breakdown/failures); standalone and embedded paths share `dashboardData()`.
9. **H4-3** ✅ — `AutomationExecutionReadModel` DTO facade; Ops Performance + activity summary consume shared ledger KPIs.
10. **H4-4** ✅ — `CashfreeIntegrityReadModel` pure-delegate facade; identical integrity consumers only; no cache/formula changes.
11. **H4-5** ✅ — `ExecutiveKpiReadModel` pure-delegate facade; Mission Control + snapshot capture only.
12. **H4-6A** ✅ — Case Queue inventory (definitions, cache, Reverb, duplicates); no code.
13. **H4-6B** ✅ — Shadow `CaseQueueReadModel` (no production consumers).
14. **H4-6C** ✅ — Ops SupportIntelligence + IRA memory/owner summary counts via ReadModel.
15. **H4-6C.1** ✅ — Adoption audit; no additional SAFE consumers; allowlist + KEEP-list tests.
16. **H4-6D** ✅ — Workforce / TeamAvailability scoped open via `CaseQueueReadModel`.
17. **H4-6E+** — Operator dashboard / Reverb consumers only after SAFE-list review.
18. **H4** — remaining shared KPI facades; Mission Control lazy load; placeholder → deep links (not deletions).
19. **H5** — retirements only with explicit approval after production validation.

---

## Risks

| Risk | Mitigation |
|---|---|
| Accidental feature removal during consolidation | Feature Preservation Rule + Register; H2/H3 prohibition on removal |
| Bookmark breakage | All routes remain; aliases permanent after H3 |
| KPI loss when merging tabs | Merge = relocate UI; services unchanged until H4 facades |
| Asymmetric hub UX (3 dashboards + 1 card grid) | By design; Administration is config/audit, not operations |
| Duplicated KPI computation | H4 read facades; no metric dropped from UI |
| Permission drift | `@can` per card/tab; no new permissions in H2 |

---

## Related docs

- [workforce-operations-blueprint.md](workforce-operations-blueprint.md)
- [product-foundations.md](product-foundations.md)
- [dashboard-architecture.md](dashboard-architecture.md)
- [hybrid-reverb-phase-3.md](hybrid-reverb-phase-3.md)
- [remaining-technical-debt.md](remaining-technical-debt.md)
