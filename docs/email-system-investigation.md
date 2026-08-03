# Email Phase 1.1 — Communication Ownership Routing

**Date:** 2026-08-03  
**Scope:** Extends Email Phase 1 — ownership routing only (no inbox, no round robin)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/email-system-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/email-system-investigation.canvas.tsx)

---

## 1. Root cause

Phase 1 linked inbound email to incidents but still used **round-robin / shift-admin** assignment for unassigned cases, and **did not notify** an existing owner when a new email arrived. Communication ownership needed to follow the **customer / Incident assignee**, not mailbox queues.

In this codebase **Incident = Service Case**; Priority 1 and Priority 2 in the brief collapse to `incidents.assigned_to_user_id`.

---

## 2. Files changed

| Area | Files |
|---|---|
| Routing | `app/Services/IncomingEmail/IncomingEmailAssignmentService.php` |
| Processor | `app/Services/IncomingEmail/IncomingEmailProcessorService.php` |
| Notification | `app/Notifications/NewEmailReceivedNotification.php` |
| Settings | `SettingsSeeder`, `ApplicationSettingsService`, `SettingsController`, `UpdateSettingsAssignmentRequest`, `resources/views/settings/partials/assignment.blade.php` |
| Tests | `tests/Feature/EmailCommunicationOwnershipRoutingTest.php`, intake Phase 1 updates |
| Docs | this file + canvas |

Settings keys:

- `assignment.communication_intake_primary_user_id` (Shubhanshi)
- `assignment.communication_intake_fallback_user_id` (Dileep)

---

## 3. Routing decision tree

```
Inbound email linked to operational Incident?
│
├─ YES, assigned_to_user_id set
│     → KEEP owner (never reassign)
│     → Notify: New Email Received
│     → Append communication on same case
│
├─ YES, unassigned
│     → Communication Intake
│           Primary available? → assign Primary
│           Else Fallback available? → assign Fallback
│           Else force Fallback (then Primary) so case is not ownerless
│     → Notify new owner: New Email Received
│     → NO round robin
│
└─ NO match / unknown customer
      → NeedsReview / possible_sales_lead (Phase 1)
      → No incident, no assignment
```

Availability for intake (workforce engine): inactive, approved leave, holiday / outside hours (`calendarAllows`), Offline status, Away while clocked-in.

Existing owner is **never** overridden by availability checks.

---

## 4. Before / after

### Before
Unassigned linked email → UniversalAssignmentEngine communication intake → Support queue **round-robin** / shift admin.  
Owned case → skip assign, **no owner notification**.

### After
Owned case → keep owner + `NewEmailReceivedNotification`.  
Unassigned → Primary → Fallback intake settings.  
Unknown → Needs Review (unchanged).  
Reply feature flag unchanged.

---

## 5. Production safety

- Existing assignee always wins; communication never transfers ownership
- No round robin on email intake path
- No duplicate incidents / parallel owners
- Unknown customers stay Needs Review
- Forced fallback only when both intake users fail soft availability (avoids ownerless cases)
- Reply flag (`INBOUND_EMAIL_REPLY_ENABLED`) unchanged; no separate routing flag

---

## 6. Rollout

1. Deploy + migrate if needed (no new migration for 1.1)
2. Re-seed settings / set Communication Intake Primary = Shubhanshi, Fallback = Dileep in Settings → Assignment
3. Verify: owned case gets notification and same assignee; unassigned routes to primary; primary Offline → Dileep
4. No inbox / RR enablement

---

## 7. Tests

| Coverage | Result |
|---|---|
| Existing owner preserved + notified | Pass |
| Unassigned → intake primary | Pass |
| Primary Offline → fallback | Pass |
| Primary inactive → fallback | Pass |
| Unknown → NeedsReview, no notify | Pass |
| Second email no parallel ownership | Pass |
| Intake Phase 1 / reply regression | Pass |

---

## STOP

No Round Robin. No Phase 2. No Inbox. No mailbox queues. No automatic ownership transfer.
