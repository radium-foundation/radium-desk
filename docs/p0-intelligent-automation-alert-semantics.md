# P0 — Intelligent Automation Health & Telegram Alert Semantics

**Status:** Implemented  
**Captured:** 2026-08-04  
**Scope:** Alerting layer only  
**Canvas:** [`p0-intelligent-automation-alert-semantics.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-intelligent-automation-alert-semantics.canvas.tsx)

---

## Summary

Historical Failed ledger rows no longer wake operators. Watchdog, Telegram, Platform Health, Automation Health, and Expand all agree on active operational health while preserving immutable audit history.

| Metric | Value |
| --- | --- |
| Scope | Alerting only |
| automation_executions | Immutable |
| Telegram dedupe | Fingerprint |
| Related tests | 35/35 |

### Objective

Dashboard, Platform Health, Executive Snapshot surfaces, and Telegram must represent ACTIVE production health — not historical audit records. Known terminal / idempotent failures must not produce Critical Alerts.

### What changed

| P0 item | Change |
| --- | --- |
| P0.1 Watchdog | Open (non-terminal) failures only; alert when count ≥ threshold |
| P0.2 Telegram | Incident fingerprint: once / suppress / clear / return / severity↑ |
| P0.3 Automation Health | Historical / Open / Critical; status uses Open/Critical |
| P0.4 Expand | Summary exposes Historical · Open · Critical independently |

### Explicitly not changed

automation_executions rows · Waiting lifecycle · Queue · Scheduler · Assignment · Attendance · Workforce · Finance · Dashboard Operations Workspace · automation business logic

### Success criteria

| Criterion | Result |
| --- | --- |
| Historical failures only → no Telegram | Pass |
| Historical failures only → Platform Healthy | Pass |
| Open failures → Telegram once + Critical | Pass |
| Repeated watchdog → no duplicate Telegram | Pass |
| Resolved → Critical clears + fingerprint removed | Pass |
| New unrelated failure → Telegram sent | Pass |
| Severity increase → Telegram sent | Pass |
| Expand shows Historical / Open / Critical independently | Pass |
| automation_executions ledger not rewritten | Pass |

---

## Architecture

### Failure classification

`AutomationFailureClassifier` classifies Failed ledger messages without rewriting rows.

**Historical (terminal) — audit**

Idempotent / already-resolved outcomes. Examples:

- `Service case is already closed.`
- `Waiting state is no longer active.`
- `Already closed - waiting cleared.`

Counted for reporting only. Never Critical / Telegram.

**Open / Critical — ops**

Non-terminal Failed today. Critical = open count ≥ `ira.watchdog.automation_failure_threshold`.

Drives Watchdog, Telegram, Platform Health status, and health calculator severity.

### Telegram fingerprint gate

`WatchdogCriticalAlertGate` stores `sha256(key|message|affectedCount)` + severity in cache. CriticalSystemAlert no longer uses the 60-minute time cooldown.

| Event | Gate behavior |
| --- | --- |
| Problem appears | shouldNotify → true · markNotified |
| Problem unchanged | same fingerprint + severity → suppress |
| Problem resolved | syncResolved clears fingerprint |
| Problem returns | no state → notify again |
| Severity increases | higher affectedCount → notify again |

### Consumer agreement

| Surface | Signal used |
| --- | --- |
| ProductionWatchdogService | open failures ≥ threshold |
| Telegram Critical Alerts | watchdog alerts + fingerprint gate |
| Automation Health status | open / critical (historical = detail) |
| Automation Health KPIs | three buckets + total failures_today |
| Platform Expand | Historical · Open · Critical in summary |
| Platform Health Automation probe | open 24h / critical today |

---

## Before / After flow

### Before

Watchdog counted every Failed row with `created_at ≥ startOfDay()`.

Eight terminal “already closed” failures → Critical Alerts + “8 automation failures today”.

Telegram dedupe key was static (`watchdog:automation:failures`) with a 60-minute cooldown → hourly re-send while count stayed ≥ threshold.

Production was already healthy; ledger history kept waking operators.

### After

Failed rows are classified: terminal → Historical; else → Open.

Watchdog alerts only on Open ≥ threshold. Message: “N open automation failure(s) require attention.”

Telegram fires once per incident fingerprint; unchanged repeats are suppressed; resolve clears; severity↑ re-alerts.

Expand / Platform Health / Automation Health show Historical, Open, and Critical independently. Platform uses Open/Critical only for health status.

### Flow (after)

| Step | Detail |
| --- | --- |
| 1 | Read Failed executions since startOfDay (immutable) |
| 2 | Classify each message → Historical or Open |
| 3 | Critical = open ≥ threshold; else Critical = 0 |
| 4 | Watchdog emits alert only if Critical > 0 |
| 5 | Gate syncResolved + shouldNotify(fingerprint) |
| 6 | Telegram send once; markNotified on success |
| 7 | Health / Expand / Platform read same buckets |

---

## Files modified / added

| File | Role |
| --- | --- |
| `app/Services/Operations/AutomationFailureClassifier.php` | New — terminal vs open failure message classification |
| `app/Services/Operations/WatchdogCriticalAlertGate.php` | New — incident fingerprint gate (appear / suppress / clear / escalate) |
| `app/Data/Operations/ProductionCriticalAlert.php` | fingerprint() + severity() for Telegram gate |
| `app/Services/Operations/ProductionWatchdogService.php` | Count open failures only; alert when ≥ threshold |
| `app/Services/Operations/IraCommunicationService.php` | Fingerprint gate; CriticalSystemAlert bypasses time cooldown |
| `app/Services/Operations/AutomationHealthService.php` | historical_failures_today / open_failures_today / critical_failures_today |
| `app/Services/Operations/AutomationHealthStatusCalculator.php` | Status from open/critical only; historical is audit detail |
| `app/Services/Platform/PlatformAutomationOverviewService.php` | Expand summary: Historical · Open · Critical |
| `app/Services/Platform/Health/AutomationHealthProvider.php` | Platform Health uses open/critical, not raw Failed count |
| `resources/views/admin/automation-health/partials/overview-cards.blade.php` | KPI cards for Historical / Open / Critical |
| `tests/Feature/IntelligentAutomationAlertSemanticsTest.php` | New P0 semantics suite |
| `tests/Feature/ProductionWatchdogTest.php` | Cooldown → fingerprint suppress rename |
| `tests/Feature/AutomationHealthDashboardTest.php` | Historical-only stays Healthy; bucket labels visible |

No changes to waiting lifecycle, queue, scheduler, assignment, attendance, workforce, finance, or Operations Workspace.

---

## Test results

| Suite | Coverage | Result |
| --- | --- | --- |
| IntelligentAutomationAlertSemanticsTest | 7 | Pass |
| ProductionWatchdogTest (related) | included | Pass |
| AutomationHealthDashboardTest | included | Pass |
| PlatformAutomationOverviewSchedulerWorkersTest | included | Pass |
| AutomationExecutionReadModelTest | included | Pass |
| Combined filter run | 35 / 35 | Pass |

### Scenarios covered

| Scenario | Assertion |
| --- | --- |
| Historical only | No watchdog automation alert; 0 Telegram CriticalSystemAlert |
| Open failures | Telegram sent once; second run unchanged → still 1 |
| Severity increase | 2 → 3 open failures → second Telegram |
| Resolved then returns | Terminal reclassify clears alert; new open → Telegram again |
| Health buckets | historical / open / critical independent; status warning on open |
| Platform Health | 5 historical → healthy; detail mentions historical |
| Expand diagnostics | message contains Historical N · Open N · Critical N |

```bash
php artisan test --filter='IntelligentAutomationAlertSemanticsTest|ProductionWatchdogTest|AutomationHealthDashboardTest|PlatformAutomationOverviewSchedulerWorkersTest|AutomationExecutionReadModelTest'
```

---

## Rollback strategy

Safe to roll back alerting-only commits. Ledger rows were never modified, so no data repair is required.

| Step | Action |
| --- | --- |
| 1 | Revert the alerting-layer commit(s) that introduced classifier + gate + health buckets |
| 2 | Deploy previous build (deskd / normal release path) |
| 3 | Optionally flush cache keys matching `watchdog:critical-fingerprint:*` |
| 4 | Confirm Telegram returns to prior cooldown behavior (expected regression: historical noise resumes) |

### Risk if rolled back

Terminal “already closed” ledger rows will again count as Critical and re-notify hourly until the calendar day rolls. Prefer keeping this alerting fix even if other P0 work is deferred.

### Forward-safe note

Expanding `TERMINAL_MESSAGE_MARKERS` is the supported way to silence additional idempotent outcomes without touching the ledger.
