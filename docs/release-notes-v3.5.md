# Release Notes — Dashboard Phase 3.5

**Release:** v3.5 — Production Stabilization & Release  
**Date:** June 2026  
**Scope:** Stabilization, cleanup, and operational readiness. No new user-facing features.

## Summary

Phase 3.5 prepares the dashboard and workspace systems for production deployment by fixing test regressions, removing dead code, consolidating client utilities, and documenting architecture for operators and developers.

## Fixes

### Quick Create Redirect Tests

PHPUnit tests updated to match the established business rule: **Dashboard Quick Create redirects back to the Dashboard** after successful service case creation. Affected tests:

- `NotificationCenterTest::test_quick_create_sends_assigned_and_high_priority_notifications`
- `ServiceCaseAssignmentTest::test_quick_create_automatically_assigns_owner_by_time`

Note: Quick create for an **existing order with matching serial** still redirects to the order show page (`order-found` status). This behavior is unchanged.

## Operational

### Historical Data Sync Command

Verified `service-cases:sync-closed-status` command:

- Closes unfinished service cases for orders that already have a transaction ID
- Supports `--dry-run` for safe preview
- Reports per-row actions, summary counts, and individual failures
- Full test coverage in `SyncCompletedOrdersServiceCasesTest` (8 tests)

**Recommended production usage:**

```bash
php artisan service-cases:sync-closed-status --dry-run
php artisan service-cases:sync-closed-status
```

## Cleanup

### Client-Side

- Extracted shared `initTooltips` into `resources/js/tooltips.js` (eliminates duplicate Bootstrap tooltip initialization between `app.js` and `live-dashboard.js`)
- Unified CSRF token access in `app.js` via `workspace/http.js`
- Removed unused workspace exports: `getWorkspace`, `getActiveWorkspaceContext`, `isWorkspaceContextConfigured`
- Removed redundant `data-workspace-fragment-loader` attribute (modal host is the single bootstrap point)
- Removed dead `refreshDashboardKpis` fallback path in response handler
- Updated `config/workspace.php` comment to reflect dashboard context declaration

### Preserved for Backward Compatibility

- Legacy service case page modals (assign, remark, status)
- Legacy routes: `incidents.assignment.update`, `incidents.status.update`, `remarks.store`
- Bulk and inline transaction assignment (active dashboard features, not removed)
- Default workspace context fallback to `ServiceCase` when context omitted

## Verification

All quality gates pass:

| Suite | Result |
|-------|--------|
| PHPUnit | 211 tests, 935 assertions — **pass** |
| Vitest | 41 tests across 8 files — **pass** |
| `npm run build` | Production Vite build — **success** |

## Documentation

New architecture and release documentation:

- `docs/workspace-architecture.md`
- `docs/dashboard-architecture.md`
- `docs/release-notes-v3.5.md` (this file)
- `docs/upgrade-notes-v3.5.md`
- `docs/remaining-technical-debt.md`
