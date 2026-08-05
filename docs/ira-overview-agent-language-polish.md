# IRA Overview — Agent Language Polish

**Date:** 2026-08-05  
**Priority:** P1  
**Type:** Presentation-only  
**Canvas:** none

---

## Objective

Refine Customer360 IRA Overview and related header surfaces so support agents see plain operational language instead of internal SLA / enum terminology.

No business logic, calculations, workflow, API, or database changes.

---

## Changes shipped

### 1 — Dashboard response overdue column

| Before | After |
|--------|-------|
| `Over` | `RO 30m`, `RO 2h`, `RO 3d`, `RO 1mo` |

Tooltip: **Response overdue by** \<duration\>. Backend `ServiceCaseSlaStatus` values unchanged.

### 2 — IRA Executive Brief priority display

| Internal | Agent display |
|----------|---------------|
| Critical | High |
| High | Medium |
| Medium / Normal | Normal |
| Low | Low |

Internal enums and priority calculation unchanged.

### 3 — Remove SLA label from Executive Brief

| Before | After |
|--------|-------|
| **SLA** — Overdue (1 Month) | **Case Delay** — 1 Month |

Warning/paused still show under **Case Delay** (`At risk`, `Paused`). IRA narrative may still mention SLA.

### 4 — Stage vs appointment condition

| Before | After |
|--------|-------|
| **Current Status** — Support appointment overdue | **Current Stage** — Support Appointment |
| **Appointment** — Overdue | **Appointment** — Overdue (1 Month) |

Stage = where the case is. Appointment row = condition with duration when overdue.

### 5 — Owner label

**Current Owner** → **Assigned To** (Executive Brief + Case Contributors).

### 6 — Operations header chips

| Before | After |
|--------|-------|
| Open · High priority · SLA breached · Needs attention | Open · High · \<dynamic overdue\> · Needs Attention |

Dynamic overdue chip (first match only):

- Appointment Overdue
- Verification Overdue
- Payment Overdue

No SLA wording in agent chips. SLA chip removed.

---

## Files

| File | Role |
|------|------|
| `app/Support/Customer360/Customer360AgentLanguagePresenter.php` | Central display mappings |
| `app/Support/Customer360/Customer360IraPanelPresenter.php` | Executive Brief labels/values |
| `resources/views/components/c360/operations-header.blade.php` | Top chips |
| `resources/views/components/c360/ira-command-center.blade.php` | Assigned To emphasis |
| `resources/views/dashboard/partials/status-sla-cell.blade.php` | RO column + tooltip |
| `tests/Unit/Customer360/Customer360AgentLanguagePresenterTest.php` | Mapping coverage |
| `tests/Unit/Customer360/Customer360IraPanelPresenterTest.php` | Brief label updates |
| `tests/Feature/Customer360ExecutiveSummaryTest.php` | No SLA in brief HTML |

### Intentionally untouched

`CaseStateBuilder`, `CaseIntelligenceEngine`, priority/SLA calculation, escalation, IRA narrative generation, permissions.

---

## Tests

- Priority label mapping (Critical→High, etc.)
- Stage / appointment separation for overdue appointments
- Assigned To / Case Delay / no SLA label in brief
- RO compact label + tooltip
- Dynamic overdue chip preference

---

## Architecture

```
CaseIntelligenceSnapshot (unchanged internal values)
  → Customer360AgentLanguagePresenter (display only)
       → Customer360IraPanelPresenter::executiveBrief()
       → operations-header chips
       → dashboard status-sla-cell (RO)
```
