# H4-6E — Operator Dashboard Summary Migration

**Phase type:** Production-safety refactor  
**Date:** 2026-07-25  
**Status:** Complete

## Scope

Migrated **operator dashboard summary counts only** in `DashboardService` to `CaseQueueReadModel`. Collections, row rendering, Reverb payloads, polling endpoints, and `DashboardSnapshot` ownership unchanged.

## Consumers migrated

| Method | Before | After |
|---|---|---|
| `statsFor()` operational KPIs | `$snapshot->operationalKpiCounts()` | `$this->caseQueue()->operationalKpiCounts(..., $snapshot)` |
| `statsFor()` SLA block | `$snapshot->slaCounts()` / splits | `$this->caseQueue()->slaCounts()` / splits |
| `slaCounts()` | `$snapshot->slaCounts()` | `$this->caseQueue()->slaCounts(snapshot: ...)` |
| `serviceCaseFilterCounts()` | `$snapshot->filterCounts()` | `$this->caseQueue()->filterCounts(..., $snapshot)` |

## Intentionally kept on DashboardSnapshot

- `snapshot()` / `activeIncidents()` / `incidentsForQueue` / `incidentsForFilter`
- `recentServiceCases` / row HTML / live poll row payloads
- `DashboardKpiAggregator::supportAgentKpis($snapshot, ...)`
- `AgentNextAppointmentResolver`
- Reverb partial row merge paths (unchanged)

## DI note

`CaseQueueReadModel` is resolved **lazily** via `app()` inside `DashboardService` (not constructor injection). Constructor injection creates a cycle:

`DashboardService` → `CaseQueueReadModel` → `OperationsQueueClassifier` → … → `DashboardBroadcastService` → `DashboardService`.

Lazy resolution preserves identical runtime behaviour without changing broadcast or container bindings.

## Verification

- `tests/Unit/Cases/CaseQueueOperatorConsumerMigrationTest.php`
- `tests/Feature/DashboardReverbMetricsConsistencyTest.php`
- Allowlist includes `DashboardService.php`

## Rollback

Restore direct `$snapshot->…` count calls in `DashboardService`; remove lazy `caseQueue()` helper.
