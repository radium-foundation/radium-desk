# Dashboard Architecture Summary

## Purpose

The dashboard is the primary operational surface for agents and admins: KPI stats, SLA alerts, filterable service case table, inline/bulk transaction assignment, quick service request creation, and workspace actions — all with background live refresh.

## Server Architecture

### Controllers

| Route | Controller | Purpose |
|-------|------------|---------|
| `GET /dashboard` | `DashboardController` | Full page render |
| `GET /dashboard/live` | `DashboardLiveController` | JSON refresh payload |
| `GET dashboard/service-cases/{incident}/row` | `DashboardServiceCaseController` | Single row HTML |
| `POST dashboard/transactions/bulk` | `OrderTransactionController` | Bulk transaction assign |
| `POST service-requests/quick` | `QuickServiceRequestController` | Quick create order + service case |

### DashboardService

Central data layer for dashboard views:

- **`statsFor(User)`** — KPI counts (orders, incidents by status, refunds, approvals, admin-only metrics).
- **`recentServiceCases(filter)`** — Filtered incident list (`all`, `pending_admin`, `completed`, `high_priority`, `overdue`, `warning`).
- **`serviceCaseRowViewData(Incident, User)`** — Row partial view model (permissions, SLA, transaction state).
- **`liveRefreshPayload(User, filter)`** — JSON bundle for live polling.

### Quick Create Flow

1. Agent submits quick create form from dashboard modal.
2. If order ID already exists with matching serial → redirect to **order show** with `order-found` status (no new service case).
3. If serial mismatch → redirect back to dashboard with validation error.
4. If new order → create order + service case, auto-assign by shift rules, send notifications, redirect to **dashboard** with:
   - `status: service-case-created`
   - `service_case_reference`
   - `reopen_quick_create: true` (reopens modal with success message)

### Transaction Assignment

Two modes on the dashboard table:

1. **Inline** — Click transaction cell → inline input → POST to order transaction route → row HTML returned.
2. **Bulk** — Select rows → batch inputs appear → concurrent POST (max 3) → rows refresh individually.

Both modes acquire workspace session locks so live refresh does not overwrite in-progress edits.

### Historical Data Sync

Artisan command `service-cases:sync-closed-status`:

- Scans orders with non-empty `transaction_id`.
- Closes unfinished service cases (`Open`, `In Progress`, `Resolved`) via `ServiceCaseStatusService`.
- Supports `--dry-run` for operational preview.
- Actor resolved from `transaction_assigned_by` → `updated_by` → `created_by`.

## Client Architecture

### Module Layout

```
resources/js/
├── app.js                  # Entry: sidebar, tooltips, transactions, workspace, quick create
├── live-dashboard.js       # 30s polling, session-aware refresh queue
├── live-dashboard-merge.js # Row-level DOM merge preserving locked rows
├── live-notifications.js   # Notification bell polling
├── tooltips.js             # Shared Bootstrap tooltip init
└── workspace/batch-session.js  # Bulk selection + batch submit UI
```

### Live Refresh

1. `#dashboard-page[data-live-url]` triggers `refreshDashboard` every 30s (configurable via `data-live-interval`).
2. Fetch `GET /dashboard/live?filter=...` returns:
   - `action_stats_html`
   - `sla_cards_html`
   - `rows[]` with `{ incident_id, html }`
   - `service_cases_empty` + `service_cases_empty_html`
3. If workspace session is active → refresh queued; flushed on session idle.
4. `mergeServiceCaseRows` updates tbody incrementally:
   - Skips locked incident IDs
   - Preserves scroll position
   - Adds fade-in for new rows when scrolled to top

### Dashboard Blade Partials

| Partial | DOM anchor | Purpose |
|---------|------------|---------|
| `action-stats` | `#dashboard-action-stats` | KPI stat cards |
| `sla-alert-cards` | `#dashboard-sla-cards` | SLA warning cards |
| `recent-service-cases` | `.dashboard-service-cases-card` | Table + bulk bar + filters |
| `service-case-row` | `#service-case-row-{id}` | Single table row |
| `transaction-id-cell` | Inline + batch editors | Transaction assignment UI |
| `quick-create-form` | `#quickCreateModal` | New service request modal |

### Workspace Integration

Dashboard page root declares `data-workspace-context="dashboard"`. Workspace triggers on row action buttons pass this context so successful actions refresh KPIs and replace the affected row instead of patching service case page targets.

### Session Interactions

| Reason | Trigger | Effect on live refresh |
|--------|---------|------------------------|
| `quick-create` | Quick create modal open | Queue refresh |
| `inline-transaction` | Inline transaction editor | Lock row + queue |
| `bulk-selection` | Checkbox selected | Lock rows + queue |
| `batch-submit` | Bulk submit in progress | Lock rows + queue |
| `workspace-modal` | Assign/remark/resolve/close modal | Queue refresh |

## CSS

Dashboard-specific styles in `resources/css/app.css`:

- `.dashboard-cases-table` — Compact table typography
- `.dashboard-bulk-bar` — Sticky bulk action toolbar
- `.transaction-id-cell`, `.batch-transaction-editor`, `.transaction-inline-editor` — Transaction UI
- `.dashboard-row-fade-in` — New row animation on live refresh
