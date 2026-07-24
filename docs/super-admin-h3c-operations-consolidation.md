# Phase H3C — Operations Hub Consolidation (Planning)

**Status:** H3C-0 planning complete · **H3C-1** ✅ · **H3C-2A** ✅ · **H3C-2B** ✅ · **H3C-3** ✅ · H3C-4+ pending  
**Prerequisite:** H3B complete (Operations hub navigation with deep links)  
**Governed by:** [Feature Preservation Rule](super-admin-four-hubs.md#mandatory-principle-feature-preservation-rule)

---

## 1. Context — H3B complete state

### Hub home

`admin.operations.index` — **Operations Control Center** (unchanged URL, unchanged live polling).

### Hub navigation (H3B → H3C-1)

Seven top-level hub tabs in **one** primary bar on Control Center (inside the dashboard tabs card):

| Hub tab | Behavior |
|---|---|
| Today / Team / Performance / System | Bootstrap tabs on Control Center; `?hub_tab=` deep link activates tab via JS |
| Automation Health | Deep link → `admin.operations.automation-health` |
| Automation | Deep link → `admin.automation.index` |
| Webhook Explorer | Deep link → `cashfree.webhook-explorer.index` |

Child pages retain standalone hub nav above page content (deep links to Control Center tabs).

### Duplication resolved in H3C-1

1. ~~**Double tab bar** on Control Center~~ — merged into single bar in dashboard tabs card header.
2. **Triple automation surface** — System tab summaries, Automation Health page, Automation Operations page (H3C-2).
3. ~~**Sidebar + hub nav**~~ — Automation Health + Automation sidebar items hidden for admin roles (**H3C-3**); hub nav consolidated.

---

## 2. End-state Operations Hub architecture (recommended)

### 2.1 Navigation model

```
Operations Hub Home (admin.operations.index)
│
├── Persistent shell (always visible on hub home)
│   ├── Live meta + polling
│   ├── Critical Alerts strip
│   ├── Command bento (overview cards)
│   ├── IRA Briefing compact
│   └── Health status compact row
│
├── Primary hub tabs (single tab bar — merge H3B hub nav + inner tabs)
│   ├── Today          [native tab — lazy live group: today]
│   ├── Team           [native tab — lazy live group: team]
│   ├── Performance    [native tab — lazy live group: performance]
│   ├── System         [native tab — lazy live group: system]
│   └── Automation     [native tab — NEW; lazy groups: automation_health + automation_pipeline]
│
├── Progressive disclosure (unchanged patterns)
│   ├── IRA Full Analysis → Modal (existing)
│   ├── Health row accordions → lazy inline detail (existing)
│   └── Automation execution detail → Drawer (existing)
│
└── Standalone forensic (hub nav link, NOT embedded body)
    └── Webhook Explorer → full page (+ show detail page)
```

### 2.2 Sidebar end-state (admin roles)

| Item | End-state |
|---|---|
| **Operations** | **Keep** — sole Operations Hub primary |
| Automation Health | **Hide** after Automation tab validated (URL alias) |
| Automation | **Hide** after Automation tab validated (URL alias) |
| Webhook Explorer | **Keep in hub nav**; sidebar optional hide (H3C-2) — URL always live |

### 2.3 URL alias map (mandatory)

| Canonical route (preserved forever) | Alias target after H3C |
|---|---|
| `admin.operations.index` | Hub home (unchanged) |
| `admin.operations.live` | Live API (unchanged) |
| `admin.operations.automation-health` | `admin.operations.index?hub_tab=automation&automation_view=health` |
| `admin.operations.automation-health.executions.show` | Drawer API (unchanged) |
| `admin.automation.index` | `admin.operations.index?hub_tab=automation&automation_view=pipeline` |
| `cashfree.webhook-explorer.index` | Unchanged (standalone) |
| `cashfree.webhook-explorer.show` | Unchanged (standalone) |
| `admin.operations.ira.feedback` | POST API (unchanged) |
| `admin.operations.radiumbox.batch-recover` | POST API (unchanged) |
| `admin.operations.telegram.broadcast` | POST API (unchanged) |

---

## 3. Feature-by-feature disposition

### 3.1 Today

| Field | Value |
|---|---|
| **Current page** | Control Center — lazy tab `today_tab` / Support Intelligence partial |
| **Recommended future destination** | **Native Control Center tab** (unchanged content) |
| **Why** | Core operational posture; already lazy-loaded; tied to live polling groups |
| **Benefits** | Single hub entry; no context switch; preserves bento → Today shortcuts |
| **Risks** | Low — already implemented; main risk is duplicate tab UI until merged |
| **Existing URL preserved?** | **Yes** — `admin.operations.index`, `?hub_tab=today` |
| **Feature preservation** | Support Intelligence summary, details collapse, team workload cards, all filters implicit in partial — **keep all** |

---

### 3.2 Team

| Field | Value |
|---|---|
| **Current page** | Control Center — lazy tab `team_tab` |
| **Recommended future destination** | **Native Control Center tab** |
| **Why** | Live team presence; shares `TeamAvailabilityOverviewService` with Workforce; belongs in operational hub |
| **Benefits** | Aligns with bento Team Load shortcut; one polling bundle |
| **Risks** | Overlap with Workforce360 — document as **ops lens** (on-duty now) vs **workforce lens** (capacity/HR) |
| **Existing URL preserved?** | **Yes** — `?hub_tab=team` |
| **Feature preservation** | Team Presence on/off duty rows, row expand, Team Telegram Status — **keep all** |

---

### 3.3 Performance

| Field | Value |
|---|---|
| **Current page** | Control Center — lazy tab `performance_tab` |
| **Recommended future destination** | **Native Control Center tab** |
| **Why** | IVR, queue, notification, automation metrics, integration quality widgets already bundled |
| **Benefits** | Consolidates operational KPIs; RadiumBox batch recovery stays as **quick action** on tab |
| **Risks** | Tab is heavy — maintain lazy load per section bundle (existing pattern) |
| **Existing URL preserved?** | **Yes** — `?hub_tab=performance` |
| **Feature preservation** | IVR health/agents/missed calls, notification/automation/queue metrics, RadiumBox/Cashfree health full widgets, device enrichment + missing serial quality — **keep all**; batch recovery form — **quick action** |

---

### 3.4 System

| Field | Value |
|---|---|
| **Current page** | Control Center — lazy tab `system_tab` |
| **Recommended future destination** | **Native Control Center tab** |
| **Why** | Runtime comms health, integration cards, activity feeds are control-center domain |
| **Benefits** | Separates runtime ops from automation ledger; feeds link forward to Automation tab |
| **Risks** | Overlap with Automation Health metrics — H4 KPI facade, not H3C removal |
| **Existing URL preserved?** | **Yes** — `?hub_tab=system` |
| **Feature preservation** | System Health 8 components, Integration Health cards, Recent Notification Failures, Recent Automation Activity (summary), Recent IRA Messages — **keep all**; summary activity links to Automation tab |

---

### 3.5 Critical Alerts

| Field | Value |
|---|---|
| **Current page** | Bento strip above tabs on hub home (`critical-alerts` partial); live-refreshed |
| **Recommended future destination** | **Persistent hub shell** (not a tab) + alert rows as **quick actions** (tab jump) |
| **Why** | Cross-cutting attention layer spanning Cashfree, RadiumBox, appointments, IRA risks |
| **Benefits** | Always visible on hub home regardless of active tab; preserves one-glance triage |
| **Risks** | Hiding inside a tab would reduce urgency — **do not move into tab** |
| **Existing URL preserved?** | **N/A** (no standalone route); deep links via `data-operations-tab-target` preserved |
| **Feature preservation** | All alert types (paid missing, webhook failures, RadiumBox sync, overdue appointments, IRA high risks), empty state, metric badges — **keep all** |

---

### 3.6 IRA Briefing

| Field | Value |
|---|---|
| **Current page** | Compact bento cell + **Modal** (full analysis) + feedback buttons + advisor insights |
| **Recommended future destination** | **Hub shell compact** + **Modal** (full analysis) — optional future **slide-over** for medium depth |
| **Why** | AI assist pattern: glance on home, depth on demand; modal already works |
| **Benefits** | No route needed; keeps hub home intelligent without navigation cost |
| **Risks** | Modal fatigue if expanded — slide-over only if modal proves insufficient (H3C-3 optional) |
| **Existing URL preserved?** | **Yes** — `admin.operations.ira.feedback` POST unchanged |
| **Feature preservation** | Compact insights, full analysis modal, IRA feedback, advisor insights — **keep all**; do not merge into Today tab body |

---

### 3.7 System Health (compact row)

| Field | Value |
|---|---|
| **Current page** | Bento health status row + expandable lazy detail + full System tab |
| **Recommended future destination** | **Hub shell** (compact) + **progressive disclosure** (existing accordions) + **System tab** (full) |
| **Why** | Three depths are intentional: at-a-glance → inline detail → full diagnostics |
| **Benefits** | Preserves Cashfree/RadiumBox/Telegram triage without loading System tab |
| **Risks** | Collapsing into one surface loses progressive disclosure — **do not flatten** |
| **Existing URL preserved?** | **N/A** (part of hub home); lazy groups `health_*` unchanged |
| **Feature preservation** | Compact row, per-integration lazy sections, full System tab components — **keep all** |

---

### 3.8 Automation Health

| Field | Value |
|---|---|
| **Current page** | Standalone `admin.operations.automation-health` — overview KPIs, breakdown, filters, activity table, failures, **detail drawer** |
| **Recommended future destination** | **Native Control Center tab — Automation** (sub-view: **Health**) |
| **Why** | H3B deep link proves it's hub-adjacent; execution ledger is ops monitoring, not forensic; drawer pattern exists |
| **Benefits** | Removes standalone page hop; unifies automation triage with System tab summaries |
| **Risks** | **High** — paginated filters, heavy queries; must lazy-load on tab activation only; drawer + `executions.show` API must remain |
| **Existing URL preserved?** | **Yes** — route alias to `?hub_tab=automation&automation_view=health` |
| **Feature preservation** | Overview strip, breakdown by type, all filters (search/type/status/date), activity table pagination, failures list, execution detail drawer, execution show JSON endpoint — **keep all** |

**Not recommended:** Drawer-only (too much content). Full page forever (defeats H3C). Embed in System tab (wrong mental model).

---

### 3.9 Automation Operations

| Field | Value |
|---|---|
| **Current page** | Standalone `admin.automation.index` — health KPI strip, action queues, recent events, repair summary, validation summary |
| **Recommended future destination** | **Native Control Center tab — Automation** (sub-view: **Pipeline** / case queues) |
| **Why** | Complements Automation Health (ledger vs case pipeline); same permission gate; highly duplicated KPIs with Health |
| **Benefits** | One Automation destination with sub-nav; reduces three-surface confusion |
| **Risks** | **Medium** — snapshot cache (`AutomationOperationsSnapshotService`) must load independently when sub-view opens |
| **Existing URL preserved?** | **Yes** — `admin.automation.index` → alias to `?hub_tab=automation&automation_view=pipeline` |
| **Feature preservation** | Health counts strip, Waiting for Serial queue table, Duplicate Serial conflicts, RadiumBox Not Found queue, Recent Automation Events, Repair Summary KPIs, Validation by product/rule/category — **keep all** |

**Sub-nav inside Automation tab (recommended):**

| Sub-view | Source partials today |
|---|---|
| Health | `admin/automation-health/*` |
| Pipeline | `admin/automation/*` |

---

### 3.10 Webhook Explorer

| Field | Value |
|---|---|
| **Current page** | Standalone `cashfree.webhook-explorer.index` + `show` detail |
| **Recommended future destination** | **Full page — standalone forensic tool** (never embed list) |
| **Why** | Payload forensics, search, pagination, security context — wide tables, bookmarkable investigations |
| **Benefits** | Clear separation: Control Center monitors health; Explorer investigates payloads |
| **Risks** | Low if kept standalone; **high** if embedded (performance, layout, lost deep links to show page) |
| **Existing URL preserved?** | **Yes** — all `cashfree.webhook-explorer.*` routes permanent |
| **Feature preservation** | Signature status card, search, log table, show page, processing status — **keep all** |

**Optional H3C-3 enhancement (non-blocking):** Open single webhook **drawer** from Cashfree health alert on hub home → loads `show` content — additive; does not replace Explorer page.

---

## 4. Consolidation matrix (summary)

| Feature | Disposition | Embed in H3C? |
|---|---|---|
| Today | Native tab | Already — merge duplicate tab bars only |
| Team | Native tab | Already — merge duplicate tab bars only |
| Performance | Native tab | Already — merge duplicate tab bars only |
| System | Native tab | Already — merge duplicate tab bars only |
| Critical Alerts | Hub shell | No move |
| IRA Briefing | Hub shell + Modal | No move |
| System Health compact | Hub shell + disclosure + System tab | No move |
| Automation Health | **New Automation tab** (Health sub-view) | **Yes — primary H3C deliverable** |
| Automation Operations | **Automation tab** (Pipeline sub-view) | **Yes — primary H3C deliverable** |
| Webhook Explorer | **Standalone forensic** | **Never embed** |

---

## 5. Pages that should never be embedded

| Surface | Reason |
|---|---|
| `cashfree.webhook-explorer.index` | Forensic table + search + pagination |
| `cashfree.webhook-explorer.show` | Full payload inspection; bookmarkable |
| Automation execution detail (drawer content) | Already drawer — keep off-canvas, not inline |
| IRA Full Analysis | Modal real estate; dense AI output |
| Live polling endpoint `admin.operations.live` | API — not UI |

---

## 6. Tools that should always remain standalone

| Tool | Route | Notes |
|---|---|---|
| Webhook Explorer | `cashfree.webhook-explorer.*` | Forensic / compliance investigations |
| Webhook show detail | `cashfree.webhook-explorer.show` | May gain optional drawer entry from alerts — page remains |
| POST recovery/actions | `radiumbox.batch-recover`, `ira.feedback`, `telegram.broadcast` | Quick actions — not pages |

---

## 7. Sidebar retirement plan

| Sidebar item | When to hide | Condition |
|---|---|---|
| Operations | **Never** | Hub primary |
| Automation Health | H3C-1 post-validation | Automation tab ships + alias tested |
| Automation | H3C-1 post-validation | Pipeline sub-view ships + alias tested |
| Webhook Explorer | H3C-2 optional | Hub nav sufficient; URL stays live |

**Never remove URLs** — only hide sidebar labels for admin roles.

---

## 8. Recommended implementation sub-phases (H3C execution — future)

| Sub-phase | Deliverable | Preservation gate |
|---|---|---|
| **H3C-0** | This document + register update | — |
| **H3C-1** ✅ | Merge duplicate tab bars (single primary nav on Control Center) | All four native tabs + `hub_tab` behavior unchanged |
| **H3C-2A** ✅ | Add **Automation** native tab; embed Automation Health via HTML fragment fetch + shared `dashboard-body` partial | Standalone Automation Health page unchanged; drawer + filters preserved |
| **H3C-2B** ✅ | Embed Automation Operations into Automation tab (**Pipeline** sub-view) | Standalone `admin.automation.index` unchanged |
| **H3C-3** ✅ | Hide Automation Health + Automation sidebar items (admin roles); hub nav deep links to embedded Automation tab | Bookmarks + standalone routes return 200 |
| **H3C-4** (optional) | Webhook drawer from Cashfree alert; optional Webhook Explorer sidebar hide | Explorer pages unchanged |
| **H3C-5** (H4 overlap) | Shared automation KPI read facade | No metric removed from UI |

---

## 9. Risks (program-level)

| Risk | Severity | Mitigation |
|---|---|---|
| Automation tab performance regression | High | Lazy load sub-views; independent caches; no eager merge of snapshots |
| Feature loss during embed | High | Feature Preservation Register checklist per partial; alias routes |
| Double nav confusion lingers | Medium | H3C-1 merges tab bars before embed work |
| Webhook Explorer embedded by mistake | High | Explicit "never embed" rule in PR review |
| Bookmark breakage | High | Permanent route aliases + feature tests per URL |
| IRA / Critical Alerts demoted | Medium | Keep on hub shell, not inside tabs |

---

## 10. Rollback strategy

1. **H3C-1** — Restore inner tab bar; hub nav reverts to H3B deep-link mode.
2. **H3C-2** — Standalone Automation pages serve content again; aliases optional redirect off.
3. **H3C-3** — Re-show sidebar children.
4. **All phases** — No route deletions; rollback is UI + redirect only.

---

## 11. Success criteria (H3C complete)

- [x] Single primary tab bar on Operations Control Center (no duplicate Today/Team/Performance/System) — **H3C-1**
- [x] Automation Health available inside hub **Automation** tab without losing sections, filters, drawer — **H3C-2A**
- [x] Automation Operations available inside hub Automation tab (Pipeline sub-view) — **H3C-2B**
- [ ] All pre-H3C URLs return 200 with identical functionality
- [ ] Webhook Explorer remains standalone full page
- [ ] Critical Alerts, IRA Briefing, health compact row remain on hub shell
- [x] Sidebar reduced to Operations primary + Webhook Explorer for admin roles — **H3C-3**
- [ ] Feature Preservation Register updated with H3C disposition per OP-* / AH-* / AU-* / WH-* row

---

## Related docs

- [super-admin-four-hubs.md](super-admin-four-hubs.md) — master hub plan + Feature Preservation Register
- [remaining-technical-debt.md](remaining-technical-debt.md) — Operations dashboard build overhead (coordinate with H3C-2 lazy load)
