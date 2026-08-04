# Operations Workspace — Phase 1

**Type:** Implementation report (navigation / UX only)  
**Date:** 2026-08-04  
**Status:** Implemented  
**Canvas:** [`operations-workspace-phase1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-workspace-phase1.canvas.tsx)  
**Prior investigation:** [operations-workspace-unification-investigation.md](./operations-workspace-unification-investigation.md)

---

## 1. Summary

Phase 1 converts Dashboard queue/KPI navigation that already stays on `/dashboard` into **soft workspace switching**:

- AJAX refresh via existing `GET /dashboard/live`
- History API (`pushState` + `popstate`)
- Panel-local skeleton (shell stays mounted)
- No business logic, permission, stats, Team Activity, Active Cases, or Refunds changes

| Metric | Value |
|---|---|
| Soft-switch targets | 9 (queues + in-dashboard KPIs) |
| Business logic changes | 0 |
| Live pipeline reused | 1 (`refreshDashboard`) |
| Rollback | Config flag |

### Success criteria

| Criterion | Status |
|---|---|
| Queue switching never reloads page | Implemented (soft switch) |
| Browser Back / Forward | `popstate` restores workspace |
| Search still works | Cleared on switch; unchanged otherwise |
| Load More still works | Uses live datasets after switch |
| Polling / Reverb unchanged | Same `liveDashboard` instance |
| Permissions / URLs / stats unchanged | Resolver wraps existing personalization |
| Legacy `queue=` / `filter=` work | Accepted + tested |
| Ready Queue title unchanged | Unchanged |

---

## 2. Behaviour

### Soft-switch targets

| Surface | Workspace id | Nav style |
|---|---|---|
| Ready Queue | `action_required` | queue / workspace |
| Exceptions | `attention` | queue / workspace |
| Scheduled | `scheduled` | queue / workspace |
| Customer Waiting | `waiting_customer` | queue / workspace |
| Hardware | `hardware` | queue / workspace |
| Overdue | `overdue` | filter / workspace |
| Open (KPI) | `action_required` | KPI → Ready |
| Assigned Cases (agent) | `my_work` | queue / workspace |
| Action Required (agent) | `my_attention` | filter / workspace |

### Click → soft switch flow

```
Click chip / KPI
  → preventDefault
  → pushState ?workspace=
  → update data-live-* datasets
  → show panel skeleton
  → GET /dashboard/live (force, reset pagination)
  → applyRows + chrome meta
  → hide skeleton
```

Dashboard shell (header, KPI host, Team Activity) stays mounted.

### URL compatibility

| URL | Behaviour |
|---|---|
| `/dashboard?workspace=action_required` | SSR + soft-switch canonical |
| `/dashboard?queue=attention` | Legacy — unchanged resolution |
| `/dashboard?filter=overdue` | Legacy — filter workspace |
| `/incidents?status=active` | Full navigation (out of scope) |
| `/refunds?status=pending` | Full navigation (out of scope) |

Internally, `OperationsWorkspaceResolver` normalizes `workspace=` / `queue=` / `filter=` into the same `operation_queue` + `service_case_filter` pair `DashboardPersonalizationService` already produced.

### Explicitly not soft-switched

- Active Cases embed (`/incidents`)
- Refund Queue embed (`/refunds`)
- Rename Ready Queue / any KPI / tab
- New Dashboard layout
- New filters or business logic
- Permission or stats changes
- Team Activity changes

---

## 3. Files changed

| File | Change |
|---|---|
| `app/Services/Dashboard/OperationsWorkspaceResolver.php` | **New** — normalize workspace/queue/filter |
| `app/Http/Controllers/DashboardController.php` | Resolve via OperationsWorkspaceResolver |
| `app/Http/Controllers/DashboardLiveController.php` | Accept `workspace=`; return chrome meta |
| `app/Http/Controllers/DashboardServiceCaseController.php` | Load-more uses resolver |
| `config/dashboard.php` | `operations_workspace_soft_switch` flag |
| `resources/js/dashboard-operations-workspace.js` | **New** — soft switch + History API |
| `resources/js/pages/dashboard.js` | Boot soft switch |
| `resources/js/live-dashboard.js` | `force` / `resetPagination` / return data |
| `resources/js/dashboard-live-query.js` | Pass `workspace=`; filter workspaces omit queue |
| `resources/views/dashboard/index.blade.php` | `live-workspace` + soft-switch attrs |
| `resources/views/dashboard/partials/recent-service-cases.blade.php` | chip `data-workspace` + skeleton |
| `resources/views/dashboard/partials/kpi-strip.blade.php` | Mark Open/Overdue/Waiting soft links |
| `resources/views/dashboard/partials/kpi-strip-item.blade.php` | Optional `workspace` prop |
| `resources/views/dashboard/partials/agent-action-cards.blade.php` | Agent KPI soft links |
| `resources/css/app.css` | Workspace skeleton styles |
| `tests/Unit/OperationsWorkspaceResolverTest.php` | **New** |
| `tests/Feature/OperationsWorkspacePhase1Test.php` | **New** |
| `tests/js/dashboard-operations-workspace.test.js` | **New** |

### Reuse

No duplicated table/row/search/load-more/live code. Soft switch calls:

```js
refreshDashboard(pageRoot, 'operations_workspace_switch', {
  force: true,
  resetPagination: true,
});
```

and applies the existing row merge path.

---

## 4. Performance impact

| Topic | Impact |
|---|---|
| Full document navigation | Removed for in-dashboard queue/KPI links — largest win (KPIs + Team Activity not re-SSR) |
| Live refresh | One forced live fetch per switch; resets to page size (not accumulated load-more count) |
| Polling / Reverb | Unchanged single liveDashboard instance; datasets updated so subsequent polls hit the new workspace |
| Skeleton | Panel-local overlay only; page shell stays visible |
| Failure path | Falls back to full navigation if live refresh returns null |
| Extra pollers | **None** |

---

## 5. Test results

| Suite | Result |
|---|---|
| Unit `OperationsWorkspaceResolverTest` (4) | Passed |
| Feature `OperationsWorkspacePhase1Test` (5) | Passed |
| Vitest `dashboard-operations-workspace.test.js` (4) | Passed |
| Vitest `live-dashboard` + `load-more` regression | Passed |
| Feature `DashboardTest` filter run | Passed |

### Coverage map

| Check | How verified |
|---|---|
| `workspace=` SSR | Feature assert `data-live-workspace` |
| `queue=` / `filter=` legacy | Feature asserts |
| live meta payload | Feature JSON paths |
| Active/Refunds not soft-marked | Feature HTML assert |
| No reload / history URL | Vitest `pushState` + `refreshDashboard` mock |
| Parse ignores `/incidents` `/refunds` | Vitest |

---

## 6. Rollback strategy

Set:

```bash
DASHBOARD_OPERATIONS_WORKSPACE_SOFT_SWITCH=false
```

or `config/dashboard.php` → `operations_workspace_soft_switch => false`.

| Step | Action |
|---|---|
| 1 | Disable soft-switch flag in env/config |
| 2 | Hard refresh browsers (Vite asset cache) |
| 3 | Confirm chip click full-navigates again |
| 4 | No DB migrate / no data rollback needed |

Links, routes, and server resolution remain intact when the flag is off (`data-operations-workspace-soft-switch="0"` skips JS interceptors).

---

## Out of scope (later phases)

- Active Cases embed on Dashboard
- Refund Queue embed on Dashboard
- Infinite scroll changes
- Renames or layout redesign
