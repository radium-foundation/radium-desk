# Operations Workspace — Phase 2

**Type:** Implementation report  
**Date:** 2026-08-04  
**Status:** Implemented  
**Canvas:** [`operations-workspace-phase2.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-workspace-phase2.canvas.tsx)  
**Prior:** [Phase 1](./operations-workspace-phase1.md) · [Investigation](./operations-workspace-unification-investigation.md)

---

## 1. Summary

Phase 2 embeds **Active Service Cases** and **Refund Queue** inside the Dashboard via soft workspace switching.

- Dashboard shell stays mounted
- Only the Operations Workspace panel host changes
- Phase 1 case queues remain unchanged
- Legacy `/incidents` and `/refunds` keep working for bookmarks and deep links

| Metric | Value |
|---|---|
| Embedded workspaces | 2 (`active_cases`, `refunds`) |
| Business logic changes | 0 |
| Case live while embedded | Paused (no duplicate polling) |
| Rollback | `DASHBOARD_OPERATIONS_WORKSPACE_PHASE2_EMBED=false` |

### Success criteria

| Criterion | Status |
|---|---|
| Active Cases open inside Dashboard | Implemented |
| Refund Queue opens inside Dashboard | Implemented |
| Browser Back / Forward | `popstate` + History API |
| Legacy routes still work | `/incidents` + `/refunds` unchanged |
| Permissions unchanged | Same `can()` / policies |
| Actions continue (view/edit/show) | Links to existing routes |
| Dashboard shell never reloads | Sibling hosts hide/show |
| Phase 1 queues untouched | Same soft-switch path |

---

## 2. Behaviour

### Soft-switch targets (Phase 2)

| Surface | Workspace id | Default filters | Endpoint |
|---|---|---|---|
| Active Service Cases | `active_cases` | `status=active` | `GET /dashboard/workspace` |
| Refund Queue | `refunds` | `status=pending` | `GET /dashboard/workspace` |

KPI links:

- Total Active Cases → `/dashboard?workspace=active_cases`
- Refunds → `/dashboard?workspace=refunds&status=pending`

### Host model (Phase 1 safe)

```
dashboard-primary-panel
├─ [data-operations-case-host]     ← Phase 1 case table (kept mounted)
└─ [data-operations-embedded-host] ← Active/Refunds HTML swapped here
```

Case-queue event listeners remain on the mounted case host. Embedded listings are fetched as HTML fragments and injected into the embedded host.

### Flow

```
Click Active Cases / Refunds KPI
  → preventDefault
  → pushState ?workspace=
  → pause case live (stopPolling)
  → hide case host / show embedded host
  → GET /dashboard/workspace?...
  → inject panel_html

Click Ready / Exceptions / … (Phase 1)
  → show case host / clear embedded host
  → resume live
  → existing Phase 1 soft switch (live refresh)
```

Filters and pagination inside embeds AJAX-refresh the embedded host only (same query behaviour as legacy index).

Show / Edit / Create actions still navigate to legacy pages (unchanged permissions and controllers).

### Live updates

- Case Reverb/poll is **paused** while an embedded workspace is active
- Resumed when returning to a case queue
- Refunds / Active Cases do **not** introduce polling

### Unchanged

- Ready Queue / Exceptions / Scheduled / Waiting / Hardware / Overdue
- Team Activity / KPI math / Assignment / Attendance / Workforce
- Permissions / Business logic

### URL compatibility

| URL | Behaviour |
|---|---|
| `/dashboard?workspace=active_cases` | SSR embed + soft-switch |
| `/dashboard?workspace=refunds` | SSR embed + soft-switch |
| `/incidents?status=active` | Full legacy page |
| `/refunds?status=pending` | Full legacy page |
| Phase 1 `queue=` / `filter=` / `workspace=` | Unchanged |

---

## 3. Files modified

| File | Change |
|---|---|
| `app/Services/Incidents/IncidentListingQuery.php` | **New** — shared Active Cases query |
| `app/Services/Refunds/RefundListingQuery.php` | **New** — shared Refunds query |
| `app/Services/Dashboard/OperationsWorkspacePanelService.php` | **New** — embed panel HTML |
| `app/Http/Controllers/OperationsWorkspaceController.php` | **New** — `GET /dashboard/workspace` |
| `app/Services/Dashboard/OperationsWorkspaceResolver.php` | Embedded workspace ids + Phase 2 flag |
| `app/Http/Controllers/DashboardController.php` | SSR embed hosts |
| `app/Http/Controllers/IncidentController.php` | Uses `IncidentListingQuery` |
| `app/Http/Controllers/RefundRequestController.php` | Uses `RefundListingQuery` |
| `resources/views/incidents/partials/index-listing.blade.php` | Extracted listing partial |
| `resources/views/refunds/partials/index-listing.blade.php` | Extracted listing partial |
| `resources/views/incidents/index.blade.php` | Thin wrapper |
| `resources/views/refunds/index.blade.php` | Thin wrapper |
| `resources/views/dashboard/index.blade.php` | Case host + embedded host |
| `resources/views/dashboard/partials/kpi-strip.blade.php` | Active/Refunds soft links |
| `resources/js/dashboard-operations-workspace.js` | Phase 2 soft switch + live pause |
| `resources/js/live-dashboard.js` | Skip row apply while embedded |
| `resources/css/app.css` | Embedded switch opacity |
| `config/dashboard.php` | `operations_workspace_phase2_embed` |
| `routes/web.php` | `dashboard.workspace` route |
| `tests/Feature/OperationsWorkspacePhase2Test.php` | **New** |
| `tests/Feature/OperationsWorkspacePhase1Test.php` | KPI soft-mark expectation updated |
| `tests/js/dashboard-operations-workspace-phase2.test.js` | **New** |

### Reuse

No duplicated tables. Controllers and Dashboard embed share:

- `IncidentListingQuery` / `RefundListingQuery`
- `incidents/partials/index-listing` / `refunds/partials/index-listing`

---

## 4. Performance review

| Topic | Impact |
|---|---|
| Shell | KPIs + Team Activity stay mounted; only primary panel host swaps |
| Case live | Paused on embed; no wasted live/rows traffic |
| Queries | Same `paginate(15)` + filters as legacy index pages |
| Partial reuse | Shared Blade + ListingQuery classes |
| Failure | Falls back to `/incidents` or `/refunds` full navigation |
| Extra pollers | **None** |

---

## 5. Test results

| Suite | Result |
|---|---|
| Feature `OperationsWorkspacePhase2Test` (6) | Passed |
| Feature OperationsWorkspace Phase 1 updated | Passed |
| Vitest phase2 embed (5) | Passed |
| Vitest phase1 soft-switch (4) | Passed |
| Incident + Refund controller smoke (32) | Passed |

---

## 6. Rollback strategy

```bash
DASHBOARD_OPERATIONS_WORKSPACE_PHASE2_EMBED=false
```

| Step | Action |
|---|---|
| 1 | Disable `phase2_embed` flag |
| 2 | Hard refresh browsers |
| 3 | KPI Active/Refunds leave Dashboard again |
| 4 | No DB rollback; `/incidents` and `/refunds` untouched |

Phase 1 soft-switch (`DASHBOARD_OPERATIONS_WORKSPACE_SOFT_SWITCH`) remains independent.
