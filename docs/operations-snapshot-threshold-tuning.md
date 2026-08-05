# Operations Snapshot — Threshold Tuning

**Status:** Implemented  
**Date:** 2026-08-05  
**Scope:** Operations Snapshot zone severity thresholds only  
**Prior context:**

- [docs/p0-operations-snapshot-terminology.md](p0-operations-snapshot-terminology.md) — Operations Snapshot = business workload KPIs (distinct from Platform Health)
- [docs/overall-system-health-terminology-alignment.md](overall-system-health-terminology-alignment.md) — top banner = combined system; zone badges unchanged

No Canvas was produced for this change.

---

## Objective

Reduce unnecessary **Operations Critical** states. Operations Snapshot measures operational workload, not production infra health. Critical should reflect exceptional operational pressure, not normal daily variance.

---

## Problem

| Before | Effect |
| --- | --- |
| Zone status = `worst()` across KPI cards | **Any single Critical card** forced zone **Operations Critical** |
| Effective sensitivity | ~12.5% operational pressure (1/8 KPIs Critical) already looked like full Critical to operators |
| Operator experience | Platform Healthy + Integration Healthy + **Operations Critical** from one elevated KPI → alert fatigue |

Platform Health and Integration Health already represent true production health. Operations Snapshot should not mirror their severity bar.

---

## Scoring model (unchanged)

Weighted health score per KPI card (equal weight = 1):

| Card status | Credit |
| --- | --- |
| Healthy | 1.0 × weight |
| Warning | 0.5 × weight |
| Critical / Disabled | 0 × weight |

```
healthScore%  = round( Σ credit / Σ weight × 100 , 1 )
pressureScore% = 100 − healthScore%
```

Same credit math as `PlatformOverallHealthService::scorePercent`. KPI weights remain **1 per card**. Individual KPI thresholds (Critical Cases ≥3, Waiting ≥10, etc.) are **unchanged**.

---

## Threshold change (only)

| Severity | Before (effective) | After |
| --- | --- | --- |
| Healthy | pressure &lt; ~20% *or* any Critical card via `worst()` | **0% – &lt; 15%** |
| Warning | mixed | **15% – &lt; 30%** |
| Critical | any Critical card **or** pressure ≥ ~20% | **≥ 30%** |

Constants: `OperationsSnapshotScoring::HEALTHY_BELOW_PERCENT = 15.0`, `CRITICAL_AT_OR_ABOVE_PERCENT = 30.0`.

### Verification examples

| Operational pressure | Zone status |
| --- | --- |
| 12% | Healthy |
| 18% | Warning |
| 29% | Warning |
| 30% | Critical |
| 48% | Critical |

### Practical shift (8 KPI cards, equal weight)

| KPI mix | Pressure | Before zone | After zone |
| --- | --- | --- | --- |
| 1 Critical, 7 Healthy | 12.5% | **Critical** | **Healthy** |
| 1 Critical + 1 Warning, 6 Healthy | ~18.8% | Critical | **Warning** |
| 2 Critical, 6 Healthy | 25% | Critical | **Warning** |
| 2 Critical + 1 Warning, 5 Healthy | ~31% | Critical | Critical |

Individual KPI cards still show their own Warning/Critical badges.

---

## Explicit non-changes

- KPI weights and per-metric thresholds  
- KPI calculations / `ExecutiveMetricProvider` rules  
- Contribution providers (`ExecutiveSnapshotContributionProvider`)  
- Overall System Health aggregation  
- Platform Health, Integration Health, Critical Alerts logic, Telegram  
- Routes, APIs, cache keys, snapshot pipeline  
- Presentation labels (Operations Critical / Warning / Healthy)

---

## Implementation

| File | Change |
| --- | --- |
| `app/Support/Platform/OperationsSnapshotScoring.php` | **Added** — weighted score + threshold mapping |
| `app/Services/Platform/Zones/ExecutiveSnapshotZone.php` | Replace `worstStatus()` with `OperationsSnapshotScoring::aggregateStatus()` |
| `tests/Unit/Platform/OperationsSnapshotThresholdTuningTest.php` | **Added** — threshold regression |

Zone summary now includes `operational_pressure_percent` for diagnostics (internal snapshot payload only; no UI change).

---

## Tests

```bash
php artisan test --filter='OperationsSnapshotThresholdTuningTest|OperationsSnapshotTerminologyTest|ExecutiveCommandCenterTest|ExecutiveMetricStatusRulesTest'
```

Individual KPI tests (`ExecutiveMetricStatusRulesTest`) and terminology tests remain green. Threshold unit tests cover boundary values and requirement examples.

---

## 1. Files changed

| File | Role |
| --- | --- |
| `app/Support/Platform/OperationsSnapshotScoring.php` | Threshold constants + weighted pressure scoring |
| `app/Services/Platform/Zones/ExecutiveSnapshotZone.php` | Use scoring for zone badge status |
| `tests/Unit/Platform/OperationsSnapshotThresholdTuningTest.php` | Threshold regression |
| `docs/operations-snapshot-threshold-tuning.md` | This report |

---

## 2. Why this is only a threshold correction

The weighted credit formula was already the de facto model in Overall System Health and documented in the platform monitoring audit. The zone previously **ignored** that score and used `worst()`, which acted like a **0% Critical threshold** for any bad KPI. This change applies the existing weighted calculation and relaxes only the **zone-level severity cutoffs** (15% / 30% operational pressure). No KPI math, weights, routes, or alert rules were modified.

---

## 3. Rollback instructions

1. Revert the four files above (or restore `worstStatus()` in `ExecutiveSnapshotZone` and remove `OperationsSnapshotScoring`).
2. Deploy. No migrations. No cache key changes.
3. Optional: flush `platform:zone:executive_snapshot:snapshot` or wait ≤120s for refresh so zone badges rebuild.

```bash
php artisan test --filter='OperationsSnapshotThresholdTuningTest|OperationsSnapshotTerminologyTest'
```

After rollback, a single Critical KPI card will again force **Operations Critical** on the zone badge.
