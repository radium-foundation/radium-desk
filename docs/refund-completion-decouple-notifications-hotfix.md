# Hotfix — Decouple Refund Completion From Notifications

**Date:** 2026-08-05  
**Priority:** P0  
**Type:** Hotfix (implementation)  
**Source of truth:** [docs/sc23098-refund-not-auto-closed-investigation.md](./sc23098-refund-not-auto-closed-investigation.md)  
**Canvas:** none

---

## Problem

After payout completion, `RefundRequestService::complete()` early-returned when `notifyCustomer()` returned `false`. That skipped:

1. Linked service case close  
2. Refund business hold clear  
3. Refund workflow status → `closed`

Production trigger (SC23098 / REF-2026-000118): WhatsApp-only channel + `INTERAKT_TEMPLATE_REFUND_CONFIRMATION` disabled → notification treated as failure → case left open with active refund hold.

---

## Required behavior (shipped)

After financial completion succeeds:

| Step | Behavior |
|------|----------|
| Close linked case | **Always** via `RefundCaseCloseService::closeLinkedCase()` |
| Clear refund hold | **Always** (inside close service) |
| Mark refund `closed` | **Always** (inside close service) |
| Customer notification | Best effort — audit + log on skip/fail |
| Requester notification | Best effort — audit + log on fail |

Notifications never abort the workflow, never roll back completion, never prevent close.

---

## Code changes

### 1. `app/Services/RefundRequestService.php`

New post-completion order:

```
DB txn → status Completed + refund.completed
RefundCompleted::dispatch (finance unchanged)
closeLinkedCase()                    ← always
try notifyCustomer()                 ← best effort
try notifyRequesterOfDecision()      ← best effort
```

Removed the `if ($customerNotified === false) return;` gate.

### 2. `app/Services/RefundNotificationService.php`

- Documented best-effort contract (return value must not gate close).
- Empty channels / all-channels-skipped → audit `outcome=skipped`, return `null`.
- Failures / exceptions → audit `outcome=failed`, log warning, return `false`.
- Requester notify wrapped in try/catch; failures audit `refund.requester_notification_failed`.

### 3. Repair command

| Item | Value |
|------|-------|
| Command | `php artisan refunds:repair-completed-open-cases` |
| Dry run | `--dry-run` |
| Selection | `status=completed` + linked case not closed + active refund hold |
| Action | Calls existing `RefundCaseCloseService::closeLinkedCase()` only |

Files:

- `app/Console/Commands/RepairCompletedRefundOpenCasesCommand.php`
- `app/Services/Refunds/RefundCompletedOpenCaseRepairService.php`

### Unchanged (compatibility)

- Refund status enum values  
- Finance / `RefundCompleted` journal listener  
- Payment execution (wallet / Cashfree / manual)  
- Approval / reject flows  
- Permissions  

---

## Tests

| Coverage | Location |
|----------|----------|
| Notification fail still closes | `RefundRequestTest::test_service_case_closes_when_customer_notification_fails` |
| WhatsApp template disabled | `RefundCompletionNotificationDecoupleTest` |
| Email unavailable | same |
| Both channels unavailable | same |
| Customer notify throws | same |
| Requester notify throws | same |
| Dispatcher returns false | same |
| Normal empty-channels close | same |
| Repair dry-run + execute | same |
| Repair skips no-hold rows | same |
| Existing hold/close regression | `BusinessHoldTest::test_refund_completed_clears_hold_and_closes_incident` |

Verified locally: filter above — **12 passed**.

---

## Ops runbook (stuck cases)

Dry run:

```bash
php artisan refunds:repair-completed-open-cases --dry-run
```

Apply (targets SC23098 / SC23667 class of rows):

```bash
php artisan refunds:repair-completed-open-cases
```

Expect: case `closed`, refund hold cleared, refund status `closed`, `refund.closed` audit with `success: true`.

---

## Audit outcomes

| Event | Meaning |
|-------|---------|
| `refund.customer_notified` + `outcome=sent` | Customer notified |
| `refund.customer_notified` + `outcome=skipped` | Channels empty or template/channel not configured |
| `refund.customer_notified` + `outcome=failed` | Dispatch failed or threw |
| `refund.requester_notification_failed` | Requester notify threw |
| `refund.closed` | Workflow close recorded (success true/false inside payload) |

---

## Risk

| Risk | Mitigation |
|------|------------|
| Customer not notified on template misconfig | Already happening; close now proceeds; ops can enable Interakt template separately |
| Repair closes unintended rows | Narrow query: completed + open case + active refund hold; dry-run first |
| Close failure after payout | Existing `RefundCaseCloseService` catch preserves completed payout and audits `refund.closed` success=false |
