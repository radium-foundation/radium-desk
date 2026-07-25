# H4-6D — Workforce Queue Summary Migration

**Phase type:** Production-safety refactor  
**Date:** 2026-07-25  
**Status:** Complete

## Scoped API

`CaseQueueReadModel` now exposes explicit scopes (pure delegates — no SQL/cache/business logic):

| Method | Meaning |
|---|---|
| `global()` | Unscoped counts (`DashboardSnapshot` with null user) |
| `forUser(User)` | Assignee-scoped counts (`openCount($user)` / `queueCounts($user)`) |
| `forTeamMembers(iterable<User>)` | Per-member `openCount` map (no Team Eloquent model exists) |

Scope object: `CaseQueueScope`.

## Consumers migrated

| Service | Call site | Before | After |
|---|---|---|---|
| `TeamAvailabilityOverviewService` | `members` / `unavailableMembers` / `memberSnapshot` | `DashboardSnapshot::load()->openCount($user)` | `$this->caseQueue->forUser($user)->openCount()` |
| `Workforce360Service` | `member` | `DashboardSnapshot::load()->openCount($subject)` | `$this->caseQueue->forUser($subject)->openCount()` |

Team list rows continue to get `open_work_count` via TeamAvailability overview (already migrated).

## Intentionally skipped

Operator dashboard, Reverb, assignment, Smart Assignment, IRA recommendations/risks/briefings, Mission Control / Executive.

## Cache / SQL

- Owner remains `DashboardSnapshot` + request `DashboardSnapshotStore`
- ReadModel adds **no** cache keys / TTLs
- Query count ≤ owner path for scoped open counts
