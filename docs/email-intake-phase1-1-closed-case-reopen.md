# Email Intake Phase 1.1 — Closed Service Case Reopen

**Date:** 2026-08-05  
**Priority:** P0  
**Type:** Implementation (narrow scope)  
**Source of truth:** [docs/email-intake-architecture-investigation.md](./email-intake-architecture-investigation.md)  
**Canvas:** none

---

## Objective

When inbound email matches a **closed** Service Case, reopen **that same case**. Never create a duplicate.

Out of scope for 1.1: AI classification, round robin, dashboard counters, attachment UX, reply changes, Gmail sync changes.

---

## Behaviour shipped

On inbound processing, after filter + existing matcher:

| Condition | Action |
|-----------|--------|
| Active SC matched | Unchanged — link, boost priority, route/notify |
| Closed SC matched (order or thread) and reopenable | Reopen → restore owner → boost priority → link → notify |
| Closed SC cancelled / duplicate-merged / soft-deleted | Do **not** reopen — fall through to historical (or auto-create if that flag is on and no reopenable case) |
| Order with no SC at all | Unchanged — historical / needs-review / auto-create |

### Reopen steps (same case)

1. System remark (`RemarkSystemSource::REOPEN`)  
2. `ServiceCaseStatusService::reopen()` → status `closed` → `open` (existing lifecycle; no new statuses)  
3. Restore previous owner (`assigned_to_user_id`, else close-outcome sticky agent) when still active/assignable  
4. Audit `incoming_email.case_reopened` (previous status/owner, message id, thread id, timestamp)  
5. `IncomingEmailLinkService::link()` — Customer360 / timeline  
6. `ServiceCasePriorityService::applyInboundLinkBoost()`  
7. `IncomingEmailAssignmentService::routeLinkedEmail()` — keeps owner + `NewEmailReceivedNotification`

---

## Safety — never reopen

| Case | Detection |
|------|-----------|
| Soft-deleted | Eloquent SoftDeletes / `trashed()` |
| Cancelled | Latest `service_case_close_outcomes.reason_for_closing = customer_cancelled` |
| Merged / duplicate | Latest close reason `duplicate_case` |
| Not closed / no order | Eligibility false |

There is no separate Incident “cancelled” or “merged” status in Desk — close outcomes are the source of truth.

---

## Files

| File | Role |
|------|------|
| `app/Services/IncomingEmail/IncomingEmailClosedCaseReopenService.php` | Eligibility + reopen/link/route orchestration |
| `app/Services/IncomingEmail/IncomingEmailCustomerMatcher.php` | Surfaces `closed_incident` when no active SC (reuses same order/thread matching) |
| `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | Closed path before historical/auto-create |
| `tests/Feature/IncomingEmail/IncomingEmailClosedCaseReopenTest.php` | Phase 1.1 coverage |
| `tests/Feature/IncomingEmailIntakePhase1Test.php` | Closed → reopen; no-incident → historical |

### Intentionally untouched

Gmail sync, History API, dedupe, filter, C360 conversation UI, reply, attachments, auto-create flag semantics (except closed case wins over create).

---

## Audit

| Event | When |
|-------|------|
| `incoming_email.case_reopened` | Reopen path — previous status/owner, message/thread ids, timestamp |
| `service_case.status_changed` | From `ServiceCaseStatusService::reopen` |
| `service_case.assigned` | When previous owner restored and differed |
| `incoming_email.linked` | Email appended to case / timeline |

---

## Tests

Covered:

- Closed case reopens  
- Owner restored  
- Priority raised  
- Timeline updated  
- Notification sent (`NewEmailReceivedNotification`)  
- Duplicate prevention (same case; second email links)  
- Cancelled close outcome ignored  
- Duplicate/merged close outcome ignored  
- Soft-deleted ignored  
- Active-case regression (link, no reopen audit)  
- Auto-create flag does not create a second case when reopenable closed exists  
- Order with zero incidents still historical  

Verified: related filters **17 passed**.

---

## Flow

```
Inbound process
  → filter
  → matcher.resolve()
       ├─ active incident → link path (unchanged)
       ├─ closed_incident (reopenable) → ClosedCaseReopenService
       │     reopen → owner → audit → link → priority → notify
       └─ else historical / needs_review / auto-create (unchanged)
```
