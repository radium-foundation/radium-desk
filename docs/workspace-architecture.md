# Workspace Architecture Summary

> **UI standard:** Customer360 workspace modal layout, components, and visual rules are documented in [customer360-workspace-modal-design-system.md](./customer360-workspace-modal-design-system.md).

## Purpose

The workspace layer provides a unified modal-based action system for service case operations (assign, remark, resolve, close) from any page context — primarily the dashboard and service case detail page — without full-page navigation.

## Server Architecture

### Entry Points

| Route | Controller | Purpose |
|-------|------------|---------|
| `GET incidents/{incident}/components/{component}` | `WorkspaceComponentController` | Load HTML fragment into modal |
| `PATCH incidents/{incident}/workspace/assign` | `WorkspaceActionController` | Submit assign action |
| `POST incidents/{incident}/workspace/remark` | `WorkspaceActionController` | Submit remark |
| `PATCH incidents/{incident}/workspace/resolve` | `WorkspaceActionController` | Resolve service case |
| `PATCH incidents/{incident}/workspace/close` | `WorkspaceActionController` | Close service case |

Legacy routes (`incidents.assignment.update`, `incidents.status.update`, `remarks.store`) remain for service case page modals and direct form posts.

### Core Services

- **`WorkspaceComponentService`** — Resolves component enum, authorizes access, builds Blade view data.
- **`WorkspaceContextResolver`** — Reads context from query param, form body, or header; defaults to `ServiceCase` when omitted.
- **`WorkspaceRefreshPolicy`** — Declares what DOM should update per `(context, component)` pair.
- **`WorkspaceRefreshRenderer`** — Renders KPI HTML, dashboard row HTML, and target fragments (timeline, header).
- **`Workspace*ActionService`** — One service per action; orchestrates business logic, refresh payload, and JSON response contract.
- **`RemarkService`** — Shared remark creation used by both workspace and legacy remark routes.

### Response Contract

Actions return JSON via `WorkspaceActionResponse`:

```json
{
  "contract_version": 1,
  "success": true,
  "message": "...",
  "action": "remark",
  "incident_id": 123,
  "toast": { "message": "...", "variant": "success" },
  "ui": { "close_workspace_host": true },
  "refresh": {
    "kpis_html": { "action_stats_html": "...", "sla_cards_html": "..." },
    "replace_row": { "incident_id": 123, "html": "...", "strategy": "replace" },
    "targets": [{ "selector": "#activity-timeline", "html": "...", "strategy": "outerHTML" }]
  }
}
```

Validation failures re-render the form fragment with errors instead of closing the modal.

### Context Model

Contexts (`config/workspace.php`) drive refresh behavior:

| Context | Typical source | Refresh behavior |
|---------|------------------|------------------|
| `dashboard` | `#dashboard-page[data-workspace-context]` | KPIs + row replace, close modal |
| `service_case` | Default when context omitted | Timeline + header patch |
| `order` | Order show page (future) | Timeline + order panel |
| `customer`, `mobile`, `api`, `ai` | Reserved for downstream surfaces |

## Client Architecture

### Module Layout

```
resources/js/workspace/
├── index.js           # Bootstrap: wires host, loader, action handler, triggers
├── context.js         # Context slugs from layout JSON bootstrap
├── session.js         # Mutual exclusion for modal, bulk, inline edit, quick create
├── fragment-loader.js # GET fragment → modal content
├── action-host.js     # POST form submit via workspaceFetch
├── response-handler.js# Apply refresh payload to DOM
├── http.js            # CSRF + timeout-aware fetch wrapper
├── error-handler.js   # Normalized error shapes
├── busy-state.js      # Loading/submit UI state
├── lifecycle.js       # beforeOpen/afterOpen/afterSubmit hooks
└── batch-session.js   # Dashboard bulk transaction selection (separate concern)
```

Shared utilities:

- `resources/js/tooltips.js` — Single Bootstrap tooltip initializer used by app, live dashboard, and workspace refresh.

### Session Coordination

`WorkspaceSession` tracks active UI reasons and locked incident IDs:

- `workspace-modal` — Modal open
- `inline-transaction` — Single-row transaction editor
- `bulk-selection` / `batch-submit` — Multi-row transaction batch
- `quick-create` — Quick create modal
- `notification-dropdown` — Notification panel open

When the session is active, live dashboard refresh is **queued** and flushed on idle to avoid clobbering in-progress edits.

### Initialization Flow

1. `app.js` calls `initWorkspace({ showToast, replaceServiceCaseRow, initTooltips, initMentionTextareas, afterOpen })`.
2. `initActionHost()` finds `[data-workspace-modal-host]` (included in `layouts/app.blade.php`).
3. Click handlers on `[data-workspace-trigger]` open components via fragment loader.
4. Form submit on `[data-workspace-action-form]` posts to workspace action routes.
5. `response-handler.js` patches DOM per refresh payload.

### Blade Fragments

Action forms live in `resources/views/service-cases/fragments/`:

- `assign-form.blade.php`
- `remark-form.blade.php`
- `resolve-form.blade.php`
- `close-form.blade.php`

Service case page still includes legacy modals (`incidents/partials/assign-modal`, `remark-modal`, `status-modals`) for keyboard shortcuts and validation re-open behavior.

## Authorization

Authorization is layered:

1. **Form requests** (`Workspace*Request`) — Gate HTTP access (e.g., `can('create', Remark::class)` + `can('view', $incident)`).
2. **WorkspaceComponentService::authorize** — Component-level policy on fragment load.
3. **Action services** — Delegate to domain services (`RemarkService`, `ServiceCaseStatusService`, etc.) which enforce business rules.

This duplication is intentional: form requests protect routes; component service protects fragment loads that bypass form submission.
