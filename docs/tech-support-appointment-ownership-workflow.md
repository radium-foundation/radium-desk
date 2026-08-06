# Tech Support Appointment Ownership Workflow

**Date:** 2026-08-06  
**Type:** Business workflow improvement  
**Scope:** Post-booking ownership handoff only — Ready Queue logic unchanged  
**Status:** Implemented · tests green

---

## Business workflow

```text
Cashfree / Service Case
        ↓
Ready Queue (admin owns — unchanged)
        ↓
Admin validates / reference issued / service completed
        ↓
Customer books Tech Support appointment
        ↓
System records Appointment Booked
        ↓
Reopen case if closed
        ↓
SupportAppointmentSmartAssignmentService
        ↓
┌─────────────────────────────┬──────────────────────────────────┐
│ Engineer available          │ No engineer available            │
│ → Assign Support Engineer   │ → Clear Ready Queue Admin        │
│   origin: appointment_      │ → pending_smart_assignment=true  │
│   smart_assignment          │ → Support Queue / Scheduled      │
│                             │   “Pending Support Assignment”   │
└─────────────────────────────┴──────────────────────────────────┘
        ↓
Customer confirmation (WhatsApp + Email) — only after ownership transition
        ↓
Appointment completed → case closed
```

---

## Ownership matrix

| Phase | Owner | Origin | Notes |
|-------|-------|--------|-------|
| Ready Queue lifecycle | Shift admin (day/night) | `auto` | **Unchanged** |
| After Tech Support booking (engineer found) | Support engineer | `appointment_smart_assignment` | Admin must leave |
| After Tech Support booking (no engineer) | Unassigned + pending | `appointment_smart_assignment` | Admin must leave; Support Queue / Scheduled |
| Existing support engineer owner | Retained support engineer | unchanged | `shouldRetainOperationalAssignee` |
| Manual support ownership | Retained | `manual` | Not overwritten by booking smart assign |

### Rules enforced

1. Ready Queue admin owns **only** during Ready Queue lifecycle.
2. On successful Tech Support booking, ownership **leaves** Ready Queue admin.
3. Uses existing `SupportAppointmentSmartAssignmentService` / `AppointmentAssignmentStrategy` — no new assignment engine.
4. Successful smart assign → `assignment_origin = appointment_smart_assignment`, reason **Tech Support Appointment**.
5. No engineer → do **not** keep Ready Queue admin; mark **Pending Support Assignment** (visible in Scheduled / Support Queue).
6. WhatsApp/Email confirmation runs **only after** reopen + ownership transition succeed.

---

## Audit sequence

| Order | Event | Meaning |
|------:|-------|---------|
| 1 | `support_appointment.booked` | Appointment Booked |
| 2 | `service_case.appointment_booking_reopened` | Case Reopened (closed bookings only) |
| 3 | `service_case.assigned` / `service_case.reassigned` **or** `service_case.pending_smart_assignment` | Assigned to Support Engineer **or** Pending Support Assignment |
| — | `reason` / label | **Tech Support Appointment** |

Timeline labels updated:

- “Tech Support appointment booked.”
- “Pending Support Assignment” (was “Pending Smart Assignment”)

---

## Failure handling

| Failure | Behavior |
|---------|----------|
| Commercial / validation reject | No appointment row; no notify |
| Reopen fails | Exception propagates; confirmation **not** sent |
| Smart assignment hard failure | Logged as `support_appointment.book.smart_assignment_failed`, rethrown; confirmation **not** sent |
| No eligible engineer | Ownership transition **succeeds** via unassign + `pending_smart_assignment`; confirmation **is** sent |
| Confirmation channel error | Logged (`confirmation_unhandled`); ownership already committed |
| Duplicate identical booking | Idempotent; no second notify / no re-transition |

---

## Files changed

| File | Change |
|------|--------|
| `app/Enums/AssignmentOrigin.php` | Added `appointment_smart_assignment` (+ `isAutomatic()`) |
| `database/migrations/2026_08_06_110000_widen_incidents_assignment_origin_column.php` | Widen column 16 → 64 |
| `app/Services/SupportAppointmentService.php` | Order: book audit → reopen → assign → waiting → **then** notify; assign failures rethrow |
| `app/Services/SupportAppointmentBookingWorkflowService.php` | `EVENT_APPOINTMENT_BOOKED`, `ASSIGNMENT_REASON` |
| `app/Services/Operations/SupportAppointmentSmartAssignmentService.php` | Origin + reason; disabled smart → pending (admin cleared) |
| `app/Services/Operations/DeferredSmartAssignmentService.php` | Same origin/reason on deferred pickup |
| `app/Services/ServiceCaseAssignmentService.php` | Clear pending for appointment-smart origin |
| `app/Services/ServiceCaseActivityTimelineService.php` | Booked + Pending Support Assignment labels |
| `app/Services/Operations/LeaveOperationalImpactService.php` | Count automatic origins via `isAutomatic()` |
| `tests/Feature/TechSupportAppointmentOwnershipWorkflowTest.php` | New coverage |
| `tests/Feature/SmartAssignmentTest.php` | Origin assertion |
| `tests/Feature/SupportAppointmentClosedCaseWorkflowTest.php` | Timeline labels |
| `docs/tech-support-appointment-ownership-workflow.md` | This doc |

### Explicitly unchanged

- Ready Queue eligibility / `ReadyQueueAssignmentStrategy` / shift-admin resolution
- Cashfree create + grace assignment
- Refund workflow
- Email closed-case reopen workflow

---

## Dashboard

After booking:

- Case is **Scheduled** (or Support My Work when assigned).
- Ready Queue admin is **not** the owner.
- Unassigned pending cases appear in **Scheduled** with `pending_smart_assignment` and timeline **Pending Support Assignment**.
- Support engineer sees the case under **My Work**.

---

## Test results

Command:

```bash
php artisan test --filter='TechSupportAppointmentOwnershipWorkflowTest|SupportAppointmentClosedCaseWorkflowTest|SmartAssignmentTest|SupportAppointmentServiceTest|SupportAppointmentConfirmationNotificationTest|DeferredSmartAssignmentTest'
```

Result: **55 passed** (253 assertions).

Verified scenarios:

| Scenario | Result |
|----------|--------|
| Open case + booking → Support Engineer assigned | ✓ |
| Closed case + booking → Reopen → Support Engineer | ✓ |
| No engineer → Support Queue / Pending Support Assignment | ✓ |
| Ready Queue admin removed from ownership | ✓ |
| Appointment under Support Team (My Work / Scheduled) | ✓ |
| Notifications only after ownership transition | ✓ |
| Existing booking idempotency / confirmation tests | ✓ |
| Existing Ready Queue logic untouched | ✓ (no Ready Queue files modified) |

---

## Deploy notes

1. Run migrations (`assignment_origin` widen) before/with deploy.
2. No Ready Queue config changes required.
3. Historical closed+scheduled orphans remain a separate repair concern (`RepairClosedAppointmentWorkflowCommand`); this change prevents new post-booking orphans from sticky admin ownership when reopen/assign runs.
