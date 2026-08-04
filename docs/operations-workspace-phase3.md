# Operations Workspace — Phase 3

**Type:** Implementation report  
**Date:** 2026-08-04  
**Status:** Implemented  
**Canvas:** [`operations-workspace-phase3.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/operations-workspace-phase3.canvas.tsx)  
**Prior:** [Phase 2](./operations-workspace-phase2.md) · [Phase 2 regression](./operations-workspace-phase2-regression-investigation.md) · [Phase 1](./operations-workspace-phase1.md)

---

## 1. Summary

Active Cases and Refunds no longer inject legacy listing pages. They render native Dashboard chrome that matches Ready Queue: same card, toolbar, chips, search-first filters, table, and footer.

| Metric | Value |
|---|---|
| Native workspaces | 2 (`active_cases`, `refunds`) |
| Query changes | 0 |
| Permission changes | 0 |
| Rollback | `DASHBOARD_OPERATIONS_WORKSPACE_PHASE3_NATIVE=false` |

| Criterion | Status |
|---|---|
| Active Cases matches Ready Queue chrome | Pass |
| Refund Queue matches Dashboard chrome | Pass |
| Existing actions preserved | View / Edit / Create / Request |
| Permissions unchanged | `incidents.view` / `refunds.view` |
| Search-first + advanced collapse | Pass |
| Pagination (page links in footer) | Pass |
| Browser Back / Forward | Unchanged soft-switch |
| Dashboard shell never reloads | Sibling hosts |
| Ready / Exceptions / Scheduled / … untouched | Pass |

---

## 2. Before / After

### Active Cases

| Before (Phase 2) | After (Phase 3) |
|---|---|
| Legacy Bootstrap cards | `dashboard-service-cases-card` |
| Large “Filters” form (7 fields always visible) | Search-first + collapsed advanced filters |
| Generic `table.table-hover` | `dashboard-cases-table` + status/source badges |
| Separate header chrome from Ready Queue | Same title / toolbar / footer language |

### Refunds

| Before | After |
|---|---|
| Four summary cards above filters | Queue chips (`dashboard-case-filter-chip`) |
| Large filter panel | Search-first + advanced collapse |
| Legacy results table | Native Dashboard table + actions |

### What still differs (by design)

| Workspace | Dataset | Columns / actions |
|---|---|---|
| Ready Queue | `DashboardService` queues | Serial / SLA / People / Timeline / Model |
| Active Cases | `IncidentListingQuery` (unchanged) | Title / Category / Created / View+Edit |
| Refunds | `RefundListingQuery` (unchanged) | Amount / Requester / View |

Ready Queue keeps queue membership and live updates. Active Cases remains the Active catalog from ListingQuery. Refunds remain non-live.

---

## 3. Files modified

| File | Change |
|---|---|
| `resources/views/dashboard/partials/active-cases-workspace.blade.php` | New — native Active Cases panel |
| `resources/views/dashboard/partials/active-cases-row.blade.php` | New — row |
| `resources/views/dashboard/partials/refunds-workspace.blade.php` | New — native Refund Queue panel |
| `resources/views/dashboard/partials/refund-workspace-row.blade.php` | New — row |
| `app/Services/Dashboard/OperationsWorkspacePanelService.php` | Render native views when Phase 3 on |
| `app/Services/Dashboard/OperationsWorkspaceResolver.php` | `phase3NativeLayoutEnabled()` |
| `config/dashboard.php` | `operations_workspace_phase3_native` |
| `resources/css/app.css` | `dashboard-ops-filter` styles |
| `.env.example` | `PHASE3_NATIVE` flag |
| `tests/Feature/OperationsWorkspacePhase3Test.php` | New |
| `tests/Feature/OperationsWorkspacePhase2Test.php` | Assert Refund Queue title |

### Unchanged

`IncidentListingQuery`, `RefundListingQuery`, Ready Queue partials, assignment, attendance, workforce, finance, permissions, soft-switch plumbing.

---

## 4. Components extracted / reused

No duplicate Ready Queue table. New workspace partials reuse Dashboard classes and existing badges.

| Component | Role |
|---|---|
| `active-cases-workspace` | Shell: title, search, advanced filters, table, pagination |
| `active-cases-row` | Row using case-reference / order-identifier / source-icon / status-badge |
| `refunds-workspace` | Shell: chips, search, advanced filters, table, pagination |
| `refund-workspace-row` | Row using refund status-badge + Dashboard cell classes |
| `dashboard-ops-filter` | Shared compact filter pattern (CSS) |
| incidents/refunds `status-badge` | Reused |
| dashboard `source-icon` / `high-priority-badge` | Reused |
| incidents/refunds `index-listing` | Kept for legacy pages + Phase 3 rollback |

---

## 5. Performance review

| Concern | Result |
|---|---|
| Query count | Unchanged — still `paginate` + categories/requesters/queueCounts as Phase 2 |
| Duplicate polling | None — `supports_live` remains false; case live paused while embedded |
| Ready Queue live | Unchanged when case host visible |
| N+1 risk | Same eager loads: `order`/`creator` and `order`/`incident`/`requester` |

---

## 6. Test results

| Suite | Result |
|---|---|
| `OperationsWorkspacePhase3Test` | 5 passed |
| `OperationsWorkspacePhase2Test` | Updated + passing |
| OperationsWorkspacePhase1 / resolver | Passing |
| `dashboard-operations-workspace*.test.js` | 12 passed |

**All Phase\* PHPUnit:** 16 passed · **Vitest soft-switch:** 12 passed

---

## 7. Rollback strategy

Restore Phase 2 legacy listing markup inside the embed host without disabling soft-switch:

```bash
DASHBOARD_OPERATIONS_WORKSPACE_PHASE3_NATIVE=false
php artisan optimize:clear
```

To leave Dashboard entirely for these KPIs: also set `DASHBOARD_OPERATIONS_WORKSPACE_PHASE2_EMBED=false`.
