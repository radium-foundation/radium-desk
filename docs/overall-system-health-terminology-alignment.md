# Overall System Health — Terminology Alignment

**Status:** Implemented (presentation only)  
**Date:** 2026-08-05  
**Scope:** Operator-facing banner label on the Platform page  
**Prior context:**

- [docs/p0-operations-snapshot-terminology.md](p0-operations-snapshot-terminology.md) — Platform Health = infra; Operations Snapshot = business KPIs
- [docs/p0-unify-platform-health-snapshot.md](p0-unify-platform-health-snapshot.md) — shared **infra** snapshot ≠ combined banner
- [docs/platform-health-disabled-aggregation-fix.md](platform-health-disabled-aggregation-fix.md) — Disabled ignored inside Platform Health only
- [docs/p0-snapshot-cache-regeneration-bug.md](p0-snapshot-cache-regeneration-bug.md) — cache key for banner remains `platform:overall-health`

No Canvas was produced for this change.

---

## Question

Is **“OVERALL PLATFORM HEALTH”** a misleading title for the top banner?

## Verdict

**Yes.** Rename to **Overall System Health**.

### Why the old title misled

| Surface | What it measures |
| --- | --- |
| Platform Health | Infrastructure only (scheduler, queue, cache, DB, …) |
| Operations Snapshot | Business / workload KPIs |
| Integration Health | External services |
| Critical Alerts | Aggregates alerts from all three |
| Top banner (`PlatformOverallHealthService`) | Weighted combine of **Platform Health + Integration Health + Operations Snapshot** |

Calling that banner “Overall **Platform** Health” collapses the combined system score into the infrastructure zone name. After Operations Snapshot terminology work, operators already distinguish Platform Health vs Operations Snapshot; the banner title had not caught up.

Internal docs historically also used “Mission Health” for the same cache (`platform:overall-health`). That name never shipped as the visible banner title. **Overall System Health** is clearer for operators and matches the user’s expected UX.

### What was not wrong

- Aggregation already spans multiple domains — no logic change required.
- Cache key `platform:overall-health`, DTO `PlatformOverallHealth`, and CSS/data hooks stay as-is (not operator copy).
- Zone title **Platform Health** remains correct for infrastructure.

---

## Rename

| Surface | Before | After |
| --- | --- | --- |
| Banner title | Overall Platform Health | **Overall System Health** |
| Tooltip / `title` | (none) | Combined domains; not infrastructure-only |
| `aria-label` | (none) | `Overall System Health: {status}` |

### Terminology map (operator-facing)

| Term | Meaning |
| --- | --- |
| Platform Health | Infrastructure zone / shared infra snapshot |
| Operations Snapshot | Business KPI zone (`executive_snapshot` route key) |
| Integration Health | External integrations zone |
| Overall System Health | Top banner combining the three |
| Critical Alerts | Alert list aggregated from the three |

---

## Explicit non-changes

No API · no routes · no cache keys · no service / aggregation / scoring logic · no thresholds · no telemetry · no database · no Critical Alerts rules · no Telegram · no zone titles other than the banner.

---

## Occurrence audit

| Location | Action |
| --- | --- |
| `resources/views/admin/platform/partials/overall-health.blade.php` | Title → Overall System Health + tooltip + aria |
| `tests/Feature/Platform/DashboardIntelligenceTest.php` | Assert new title; deny old |
| `app/Support/Platform/OverallSystemHealthPresentation.php` | **Added** central presentation constants |
| `tests/Feature/Platform/OverallSystemHealthTerminologyTest.php` | **Added** regression |
| `IntegrationHealthContributionProvider` PHPDoc | Mission Health → Overall System Health (docs only) |
| `PlatformHealthSnapshotService::aggregateOverall` PHPDoc | Clarified = infra snapshot overall, not the banner |
| Class / cache / data attributes (`platform:overall-health`, `data-platform-overall-health`) | **Unchanged** (not display copy) |
| Historical docs under `docs/p0-*` | Left as historical record |

Post-change scan of app/resources/tests (excluding intentional `assertDontSee`): **zero** remaining “Overall Platform Health” labels.

---

## Tests

```bash
php artisan test --filter='OverallSystemHealthTerminologyTest|test_platform_index_shows_overall_health_banner|OperationsSnapshotTerminologyTest'
```

Result: 9 / 9 passed.

---

## 1. Files changed

| File | Role |
| --- | --- |
| `app/Support/Platform/OverallSystemHealthPresentation.php` | Presentation constants (title, description, tooltip) |
| `resources/views/admin/platform/partials/overall-health.blade.php` | Banner title, tooltip, aria-label |
| `app/Services/Platform/Health/Contributors/IntegrationHealthContributionProvider.php` | Comment wording only |
| `app/Services/Platform/Health/PlatformHealthSnapshotService.php` | Comment wording only (disambiguate infra vs banner) |
| `tests/Feature/Platform/DashboardIntelligenceTest.php` | Banner assertion update |
| `tests/Feature/Platform/OverallSystemHealthTerminologyTest.php` | New terminology regression |
| `docs/overall-system-health-terminology-alignment.md` | This report |

---

## 2. Why this is only a terminology correction

The banner already aggregated Platform Health, Integration Health, and Operations Snapshot via `PlatformOverallHealthService` + contribution providers. The bug was **naming**, not math: the label said “Platform” while the value meant “system.” Presentation constants and the blade title were updated; no probe, weight, status mapping, cache write, or route changed.

---

## 3. Rollback instructions

1. Revert the files listed above (or restore banner string to `Overall Platform Health`).
2. Redeploy. No migration. No cache flush required for correctness (labels are rendered from Blade/constants, not baked into `platform:overall-health` payload status fields).
3. Optional: confirm Platform index shows the prior title.

```bash
# After revert
php artisan test --filter='OverallSystemHealthTerminologyTest|test_platform_index_shows_overall_health_banner'
```
