# Platform Health — Disabled Aggregation Fix

**Status:** Fixed  
**Date:** 2026-08-04  
**Scope:** Shared Platform Health snapshot overall aggregation only  
**Related investigation:** [docs/p0-platform-snapshot-writer-audit.md](p0-platform-snapshot-writer-audit.md)

---

## Objective

Platform Health must represent the health of **enabled** infrastructure.

Disabled components are informational. They must not degrade overall Platform Health.

---

## Root cause

Confirmed earlier: a single writer (`PlatformHealthSnapshotService::store` via `probe`) persisted overall status using `PlatformHealthStatus::worst()` across **all** components.

`Disabled` has severity 20 and `Healthy` has severity 10, so a disabled queue or automation forced overall **Disabled** even when every active subsystem was Healthy.

---

## Change

| Item | Detail |
| --- | --- |
| File | `app/Services/Platform/Health/PlatformHealthSnapshotService.php` |
| Method | `aggregateOverall()` used by `probe()` |
| Enum / severity | **Unchanged** |
| Component statuses | **Unchanged** (cards still show Disabled) |
| Cache keys / pipeline / UI | **Unchanged** |

### Aggregation rules

1. Ignore components with status `Disabled`.
2. Overall = `worst()` of remaining statuses (`Healthy` / `Warning` / `Critical`).
3. If no active components remain (all Disabled or empty list) → overall `Disabled` (existing fallback).

---

## Verification scenarios

| # | Inputs | Overall | Cards |
| --- | --- | --- | --- |
| 1 | Queue Disabled, Automation Disabled, rest Healthy | **Healthy** | Queue/Automation still Disabled |
| 2 | Queue Disabled, Database Critical | **Critical** | — |
| 3 | Automation Warning, others Healthy | **Warning** | — |
| 4 | Everything Disabled | **Disabled** | — |

---

## Tests

- `tests/Unit/Platform/PlatformHealthDisabledAggregationTest.php`
- `tests/Feature/Platform/PlatformHealthDisabledAggregationFeatureTest.php`

Existing Platform Health tests remain green.

---

## Rollback

Revert the aggregation change in `PlatformHealthSnapshotService` only.

- No data migration
- No cache migration
- No UI rollback

---

## Not changed

Platform Health component UI, Operations Snapshot, Executive KPIs, Critical Alerts, Telegram, Automation/Queue/Scheduler providers, routes, APIs, cache keys, snapshot pipeline.
