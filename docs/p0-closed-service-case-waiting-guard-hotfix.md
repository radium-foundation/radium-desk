# P0 Hotfix — Closed Service Case Waiting Guard

**Date:** 2026-08-04  
**Priority:** P0 production hotfix  
**Status:** Implemented · related tests 51/51 passed  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-closed-service-case-waiting-guard-hotfix.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-closed-service-case-waiting-guard-hotfix.canvas.tsx)

---

## Summary

A closed Service Case is **terminal**. It cannot restart Customer Waiting, send missing-serial reminders/WhatsApp, create orphan waiting rows, or fail later with “Service case is already closed.”

### Root cause (proven)

`MissingSerialAutomationService::resolveIncident` fell back to `latestIncident()` after close → `ensureSerialWaitingState()` created a new waiting row → later `auto_close` recorded Failed.

### Fix strategy (smallest safe)

| Layer | Change |
|-------|--------|
| 1 · Missing serial | `activeIncident()` only; skip + audit if closed; candidate query requires open case |
| 2 · Waiting service | `start` / `ensure` on Closed return `null` — never create waiting |
| 3 · Auto-close | Already closed → Success, clear orphan, audit “Already closed - waiting cleared.” |
| 4 · Repair | `customer-waiting:clear-orphans-on-closed [--dry-run]` |

### Explicitly unchanged

Waiting policy, SLA, reminder timings, assignment, dashboard, queue, attendance, workforce, finance — not modified.

---

## Files modified

| File | Change |
|------|--------|
| `app/Services/MissingSerial/MissingSerialAutomationService.php` | Stop `latestIncident` fallback; require active case; skip closed with audit |
| `app/Services/MissingSerial/MissingSerialAutomationAuditService.php` | `EVENT_SKIPPED_CLOSED_CASE` + `recordSkippedClosedCase()` |
| `app/Services/IncidentWaitingStateService.php` | `start` / `ensure` return `null` on Closed — never create waiting |
| `app/Services/Automation/CustomerWaitingLifecycleService.php` | autoClose already-closed → success + clear orphan waiting |
| `app/Services/Automation/CustomerWaitingLifecycleRepairService.php` | Extract `repairOrphansOnClosed()` |
| `app/Console/Commands/ClearOrphanWaitingOnClosedCasesCommand.php` | New: `customer-waiting:clear-orphans-on-closed --dry-run` |
| `Workspace*ActionService` (3 files) | Null-safe ensure waiting on closed |
| `tests/Feature/Automation/ClosedServiceCaseWaitingGuardTest.php` | New P0 guard coverage |

### 1. MissingSerialAutomationService

- `resolveIncident()` returns only `activeIncident()` — no `latestIncident()` fallback.
- Before contact/escalate: if closed / inactive → outcome `skipped`, audit `missing_serial.skipped_closed_case`, no notification, no waiting.
- `candidateOrdersQuery()` requires an operationally active incident.

### 2. IncidentWaitingStateService

`start()` / `ensureSerialWaitingState()` / `ensureCustomerNotRespondingWaitingState()` return `null` when the incident is Closed. No row created. No waiting-started audit.

### 3. CustomerWaitingLifecycleService::autoCloseForNoResponse

Closed incident → clear active waiting → audit `service_case.customer_waiting_closed_cleared` → `ActionHandlerResult::success` with message `Already closed - waiting cleared.` Runtime records **Success**, not Failed.

---

## Repair command

```bash
php artisan customer-waiting:clear-orphans-on-closed --dry-run
php artisan customer-waiting:clear-orphans-on-closed
```

Finds active waiting + closed Service Case. Clears waiting. Writes audit. Reports **Total found** / **Total repaired**. Never sends customer notifications.

Also available via existing `customer-waiting:repair-lifecycle` (orphan path extracted to `repairOrphansOnClosed()`).

### Post-deploy ops sequence

| Step | Action |
|------|--------|
| 1 | Deploy hotfix |
| 2 | Dry-run `clear-orphans-on-closed` — note Total found |
| 3 | Apply `clear-orphans-on-closed` — confirm Total repaired |
| 4 | Confirm Critical Alerts stop accumulating already-closed failures |

---

## Test results

**51/51 passed** (0 failed)

Filter: `ClosedServiceCaseWaitingGuardTest` | `CustomerWaitingLifecycleRepair*` | `CustomerWaitingLifecycleTest` | `MissingSerialAutomationTest` | `IncidentWaitingStateTest`

| Success criterion | Result |
|-------------------|--------|
| Closed case never starts waiting | Pass |
| Missing serial skips closed cases | Pass |
| No WhatsApp / Email on closed | Pass |
| Waiting state not created | Pass |
| Auto-close on closed → success + clear | Pass |
| Orphan waiting cleared via command | Pass |
| Open cases unchanged | Pass |
| Timing / policy / SLA unchanged | Pass |

New test file: `tests/Feature/Automation/ClosedServiceCaseWaitingGuardTest.php`

---

## Rollback strategy

Safe to revert code; re-run repair if needed. Revert the hotfix commit(s). Orphan-clear audits already written remain correct. If rollback restores the `latestIncident` fallback, orphans can reappear until the guard is re-deployed.

| Scenario | Action |
|----------|--------|
| Guard causes unexpected skips on open cases | Revert MissingSerial + WaitingStateService changes; keep auto-close success path if desired |
| Repair cleared too aggressively | Waiting rows are `cleared_at` only — reopen case and start waiting again if needed |
| Full rollback | `git revert` hotfix commit; no schema migrations in this change |

---

## Success criteria

Closed Service Cases stay terminal. Automation dashboard stops accumulating “Service case is already closed” failures. Open-case behaviour and reminder timing remain unchanged.
