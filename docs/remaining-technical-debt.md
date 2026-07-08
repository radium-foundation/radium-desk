# Remaining Technical Debt

Items identified during Phase 3.5 stabilization. Ordered by impact.

## High Priority

### Dual Action Surfaces on Service Case Page

The service case show page maintains **both** workspace modal triggers and legacy Bootstrap modals (`assign-modal`, `remark-modal`, `status-modals`). Keyboard shortcuts in `service-case-show.js` open legacy modals. Workspace actions from the dashboard use the unified modal host.

**Impact:** Two code paths for the same operations; legacy routes must remain until service case page fully migrates to workspace triggers.

**Recommendation:** Migrate service case page quick actions to `[data-workspace-trigger]` and remove legacy modals in a future phase.

### Authorization Duplication

Workspace authorization exists in both Form Requests (`Workspace*Request::authorize`) and `WorkspaceComponentService::authorize`. This is intentional for route vs. fragment-load protection but increases maintenance when policies change.

**Recommendation:** Extract shared authorization rules into policy methods or a dedicated `WorkspaceAuthorizationService`.

## Medium Priority

### Inline vs. Bulk Transaction Fetch Paths

Dashboard transaction save uses raw `fetch` in `app.js` (inline) and `batch-session.js` (bulk), while workspace actions use `workspaceFetch` with timeout handling. Inline/bulk do not benefit from unified timeout or error normalization.

**Recommendation:** Route inline and bulk transaction POSTs through `workspace/http.js` for consistent error UX.

### Live Refresh Polling

Dashboard and notifications use 30-second HTTP polling. No WebSocket/Reverb integration is planned for v3.5 per scope constraints.

**Recommendation:** Evaluate Reverb or SSE only if polling latency becomes an operational issue.

### Operations Dashboard Build Overhead

`/admin/operations` rebuilds the full metric bundle on every live poll, even when `?groups=` requests only a subset of sections. Profiling during P08-07-019 IVR analytics work identified these bottlenecks (not addressed in that phase):

- `DashboardSnapshot::load()` repeated per request — loads all active incidents with relations
- Live endpoint always calls `dashboardData()` — partial groups only skip HTML rendering, not DB work
- Unbounded today's audit log and automation execution `->get()` loads
- Ira briefing refresh on above-the-fold poll adds team performance metrics and historical incident scans

**Recommendation:** Share one `DashboardSnapshot` per request, build only bundles needed for requested live groups, and replace wholesale `->get()` loads with targeted aggregates.

### Default Workspace Context Fallback

Requests without explicit context default to `ServiceCase` per `config/workspace.php`. Order and customer page roots do not yet declare `data-workspace-context`.

**Recommendation:** Add context declarations to order show and future customer surfaces; consider making context required once all pages declare it.

## Low Priority

### Duplicate DOM Patch Helpers

`replaceInnerHtml` is implemented separately in `live-dashboard.js` and `response-handler.js`. Consolidation would reduce drift but has minimal runtime impact.

### Remark Route Duplication

`RemarkController` (legacy redirect flow) and `WorkspaceRemarkActionService` (JSON refresh flow) both call `RemarkService`. Delete path only exists on legacy route.

**Recommendation:** Add workspace delete or retire legacy remark form when service case page migrates.

### Config Placeholder Contexts

`WorkspaceContext` enum includes `Customer`, `Mobile`, `Api`, `Ai` with policy stubs but no page implementations.

**Recommendation:** Remove unused contexts or implement when surfaces are built.

### Vitest Coverage Gaps

JS tests cover session, batch, live dashboard merge, error handler, lifecycle, and busy state. Not covered:

- `fragment-loader.js`
- `action-host.js`
- `response-handler.js`
- `app.js` integration (quick create, inline transaction)

**Recommendation:** Add focused unit tests for response handler DOM patching.

## Out of Scope (Explicitly Excluded from v3.5)

- Laravel Reverb / WebSockets
- Livewire components
- New UI features
- Push-based real-time updates
