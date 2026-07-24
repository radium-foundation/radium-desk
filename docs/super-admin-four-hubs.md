# Super Admin Four-Hub Consolidation

Planning inventory for consolidating Super Admin surfaces into four operational hubs **without changing business functionality**. Routes, permissions, and controllers stay canonical until later phases explicitly alias or nest them.

## Principles

- One Super Admin application
- RBAC + scope-based access (existing Spatie permissions / policies / Workforce360 self-team-member)
- Feature flags for optional modules (reuse System Settings + hybrid realtime map; no hub-level kill switches for core admin)
- Card-first UI, drawer-first workflow, independent card loading/refresh
- Preserve existing functionality; prefer nav + deep links before service merges

## Target hubs

| Hub | Primary entry (today) | Purpose |
|---|---|---|
| **Mission Control** | `admin.platform.index` (`/admin/platform`) — formerly “Command Center” | Executive / platform health, cross-hub attention |
| **Operations** | `admin.operations.index` (`/admin/operations`) | Control Center, automation, integrations health |
| **Workforce** | `workforce.index` (`/workforce`) | Team 360, performance, calendar, leave (admin) |
| **Administration** | `admin.system-settings.index` | Users, audit, system/app settings |

## Page → hub map

### Mission Control

| Surface | Route name | Permission / gate |
|---|---|---|
| Command Center / Platform cards | `admin.platform.*` | `platform-dashboard.view` |

### Operations

| Surface | Route name | Permission / gate |
|---|---|---|
| Operations Control Center | `admin.operations.index`, `admin.operations.live` | `operations-dashboard.view` |
| Automation Health | `admin.operations.automation-health*` | `automation-operations.view` |
| Automation | `admin.automation.*` | `automation-operations.view` |
| Cashfree Webhook Explorer | `cashfree.webhook-explorer.*` | `cashfree-webhook-logs.view` / `viewAny` CashfreeWebhookLog |

### Workforce

| Surface | Route name | Permission / gate |
|---|---|---|
| Workforce360 team | `workforce.index`, `workforce.show` | `workforce360.viewTeam` / `viewMember` |
| Team Performance | `admin.workforce.performance.*` | `team-performance.view` |
| Company Holidays | `admin.workforce.holidays.*` | `workforce-calendar.manage` / CompanyHoliday policy |
| Leave Requests (admin review) | `leave-requests.*` | `leave-requests.*` |

**Out of Super Admin hub primary nav (self surfaces):**

| Surface | Route name | Notes |
|---|---|---|
| My Workforce | `my-workforce.index` | `workforce360.viewSelf` |
| Your Performance | `my-performance.index` | Support / specialist roles |

### Administration

| Surface | Route name | Permission / gate |
|---|---|---|
| System Settings | `admin.system-settings.*` | `system-settings.manage` |
| Audit Logs | `audit-logs.*` | `audit-logs.view` / AuditLog policy |
| Users | `users.*` | `users.view` / `users.manage` |
| Application Settings | `settings.*` | Superadmin + SettingPolicy |

### Not consolidation targets (day-to-day operator workflows)

Dashboard, Search, Orders, Service Cases, Approvals, Refunds — remain under the operator **Operations** sidebar section.

## Permission matrix (hub primaries)

| Hub primary | `admin` | `operations_admin` | `superadmin` |
|---|---|---|---|
| Mission Control (`platform-dashboard.view`) | No | Yes | Yes |
| Operations (`operations-dashboard.view`) | Yes | Yes | Yes |
| Workforce (`workforce.view` / team gate) | Yes | Yes | Yes |
| Administration (`system-settings.manage`) | Yes | Yes | Yes |
| Application Settings (`settings.*`) | No | No | Yes |

**Known asymmetry:** plain `admin` cannot open Mission Control / Command Center today. H1 must not grant new permissions — only regroup navigation. Closing that gap is a separate RBAC decision.

## Scaffolding status

| Hub | Exists | Gap |
|---|---|---|
| Mission Control | Platform card registry + per-card refresh API | Rename/IA; placeholders; eager first paint |
| Operations | Lazy `?groups=` Control Center | Nest Automation / Health in hub (H2+) |
| Workforce | Feature pages exist | No unified hub shell (H2) |
| Administration | Separate full pages | No hub shell (H2) |

Reusable patterns:

- Platform cards: `app/Contracts/Platform/PlatformCardProvider.php`, `resources/js/platform-dashboard.js`
- Operations independent sections: `OperationsDashboardSectionBundles`, `resources/js/operations-dashboard.js`
- Drawer-first: Automation Health offcanvas; Customer360 drawer (operator pattern)

## Migration phases

| Phase | Deliverable | Rollback |
|---|---|---|
| **H0** | This document | Delete doc |
| **H1** | Sidebar regroup into 4 hubs; all old routes live | Revert sidebar |
| **H2** | Thin hub index pages (deep-link card grids) | Remove hub routes |
| **H3** | In-hub secondary nav; keep URL aliases | Hide secondary nav |
| **H4** | Mission Control real cards / lazy first paint | Revert providers |
| **H5** | Optional cleanup / renames after aliases | Keep aliases |

## H1 scope (implemented with this plan)

- Nav labels: Mission Control, Operations, Workforce, Administration
- Primary links point at existing routes above
- Deep links to Automation, Automation Health, Webhook Explorer, Team Performance, Holidays, Users, Audit, Application Settings remain available
- No controller rewrites, no permission changes, no route deletions

## Risks

1. Permission drift if hubs imply access the role lacks (especially Mission Control for plain `admin`)
2. Bookmark breakage if pages are deleted instead of aliased
3. Performance regression if heavy pages are inlined into one shell
4. Naming collision: historical “Command Center” on platform vs Operations overview
5. Accidental merge of agent self-service into Super Admin hubs

## Related docs

- [workforce-operations-blueprint.md](workforce-operations-blueprint.md)
- [product-foundations.md](product-foundations.md)
- [dashboard-architecture.md](dashboard-architecture.md)
- [hybrid-reverb-phase-3.md](hybrid-reverb-phase-3.md) (feature-flag patterns)
- [remaining-technical-debt.md](remaining-technical-debt.md) (Operations dashboard build overhead — separate from hub IA)
