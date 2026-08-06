# RD3473671 — Why was the appointment scheduled under Shipra?

**Date:** 2026-08-06  
**Priority:** P0 production (read-only)  
**Status:** Root cause proven · no code or production changes made  
**Prod HEAD:** `61de1e49`  
**Timezone:** Asia/Kolkata (IST)  
**Canvas:** [`rd3473671-appointment-assignment-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/rd3473671-appointment-assignment-investigation.canvas.tsx)

---

## Bottom line

The appointment is “under Shipra” because **SC25967 was auto-assigned to Shipra as night-shift Ready Queue admin at 19:38:02 IST on 2026-08-04**, and that ownership **never changed** afterward.

`support_appointments` has **no engineer/assignee column**. UI “Scheduled → Shipra” is case ownership (`incidents.assigned_to_user_id = 3`) plus a scheduled appointment row.

**Appointment smart assignment did not choose Shipra.** There is no `service_case.assigned` / `reassigned` audit at booking time, and `incidents.updated_at` remains the close timestamp (`2026-08-04 20:28:43`).

Booking was **web** (`support_appointment_web`) after Shipra had already closed the case. Confirmation WhatsApp went out, but the case **stayed closed** with Shipra still owner — reopen + smart-assign after booking did not persist.

---

## Identity

| Entity | Value |
|--------|-------|
| Order | **RD3473671** (`orders.id` = 25507) |
| Order status | `active` |
| Serial / model | `9440942` / MFS 110 (`device_model_id` = 1) |
| Transaction | `0c13224c965f4f3d` |
| Customer | chandramani · 7079107541 · nihalsingh6361@gmail.com |
| Service case | **SC25967** (`incidents.id` = 26045) |
| Case status (now) | `closed` |
| Assignee (now) | **Shipra** (user 3) — roles: `admin`, `operations_admin` |
| Assignment origin | `auto` |
| Created by | Ravi / system user (user 1) — Cashfree automation actor |
| Appointment | **#594** — status `scheduled`, date **2026-08-05**, slot **evening** |
| Booking source | **Web** (`context.source = support_appointment_web`) |
| Primary queue (now) | `completed` (`isScheduled = false` because case is closed) |

---

## Root cause (causal chain)

```text
2026-08-04 19:36:13  Cashfree payment creates order + SC25967
                     → UniversalAssignmentEngine::assignOnCreate
                     → automation grace (60s)

2026-08-04 19:37:03  Identity/enrichment OK (serial + model)
                     → Ready Queue eligibility passes

2026-08-04 19:38:02  ReadyQueueAssignmentStrategy
                     → ServiceCaseAssignmentService::assignToShiftAdminAfterValidation
                     → ReadyQueueAdminAssignmentService::resolveEligibleAdmin
                     → night window (after 18:30) → assignment.night_shift_admin_user_id = 3
                     → ASSIGN Shipra (override_reason = shift_admin)
                     Audit #788211

2026-08-04 20:28:43  Shipra enters service reference + closes case
                     (awaiting_product_details → closed)
                     Audit #788967 · incidents.updated_at stops here

2026-08-04 21:00:31  Customer books Tech Support appointment via WEB signed URL
                     SupportAppointmentService::book
                     → creates appointment #594 (scheduled, 05 Aug evening)
                     → sends WhatsApp confirmation (dispatch #15207)
                     → reopenClosedIncidentIfNeeded + assignAfterBooking
                       DID NOT PERSIST (no reopen/assign audits;
                       status still closed; updated_at still 20:28:43)

Result: scheduled appointment remains on a closed case still owned by Shipra
        from the earlier Ready Queue night-shift assignment.
```

### Exact method responsible for Shipra ownership

| Step | Symbol |
|------|--------|
| Entry | Cashfree create → `UniversalAssignmentEngine::assignOnCreate` + grace |
| Strategy | `ReadyQueueAssignmentStrategy::assign` |
| Assign | `ServiceCaseAssignmentService::assignToShiftAdminAfterValidation` |
| Admin pick | `ReadyQueueAdminAssignmentService::resolveEligibleAdmin` |
| Window | `ServiceCaseAssignmentService::resolvePrimaryAssigneeUserId` — 19:38 > day end 18:30 → **night** |
| Config | `assignment.night_shift_admin_user_id = 3` (Shipra) |
| Audit proof | `#788211` `service_case.assigned` · `override_reason: shift_admin` |

Live replay at `2026-08-04 19:38:02 Asia/Kolkata`:

- Candidate IDs: `[3, 4]` (Shipra primary, Dileep fallback)
- Resolved admin: Shipra
- Shipra approved leave that moment: **false**

---

## Expected vs actual

| Question | Answer |
|----------|--------|
| Should Shipra have received **Ready Queue** ownership at 19:38? | **Yes** — she is configured night-shift admin; time was after 18:30; leave gate did not exclude her |
| Should Shipra have been chosen as **Tech Support appointment engineer**? | **No** — appointment smart assignment uses `SUPPORT_TEAM_ROLES` (agent / support_specialist / customer_coordinator), not admins |
| Did appointment smart assignment assign Shipra? | **No** — no booking-time assignment audit; ownership unchanged since 19:38 |
| What should have happened at web booking (21:00)? | `reopenClosedIncidentIfNeeded` → open case + `service_case.appointment_booking_reopened`, then `SupportAppointmentSmartAssignmentService::assignAfterBooking` → support-pool smart assign (or `pending_smart_assignment`) |
| What actually happened at booking? | Appointment row + WhatsApp confirmation only; case remained **closed** under Shipra |

---

## Chronological timeline (IST)

| Time | Event | Actor / system | Evidence |
|------|-------|----------------|----------|
| 19:36:13 | Order + SC25967 created (Cashfree) | System (user 1) | Incident/order rows; audit `automation_pending` / `payment_received` |
| 19:36:20 | Waiting RadiumBox | System | Audit `waiting_radiumbox` |
| 19:37:02–03 | Enrichment: serial `9440942`, model MFS 110 | System | Order audits `enrichment_*`, `missing_serial.completed` |
| 19:38:02 | **Assigned to Shipra** (`auto`, `shift_admin`) | System (user 1) | Audit **#788211** |
| 20:18–20:19 | Missed-call recovery attachments (phone 7079107541) | System | Audits `missed_call_recovery.answered_attached` |
| 20:28:43 | Service reference set; case **closed** | Shipra | Audits `#788965–788967`; `updated_by=3` |
| 20:29:35–38 | Driver installation guide WhatsApp/email | Automation as Shipra | Audits + Interakt hooks |
| 21:00:31 | Appointment **#594** created (05 Aug evening) | Web booking | `support_appointments` row |
| 21:00:32 | Booking confirmation WhatsApp sent | Automation | Dispatch **#15207** `source=support_appointment_web`; audit `#789508` |
| — | **No** `appointment_booking_reopened` / status open / reassignment | — | Absent from `audit_logs`; `updated_at` still 20:28:43 |
| 05 Aug 14:41+ | Shipra views AI workbench / order | Shipra | View audits only |
| Now | Case `closed`, assignee Shipra, appt still `scheduled` | — | Live row + classifier `completed` |

---

## Assignment history

| # | Timestamp | Previous | New | Origin / reason | Actor | Manual? |
|---|-----------|----------|-----|-----------------|-------|---------|
| 1 | 2026-08-04 19:38:02 | `null` | Shipra (3) | `auto` + `override_reason=shift_admin` | System (1) | **No** |
| — | (no further ownership changes) | Shipra | Shipra | — | — | — |

No manual assign / reassign / schedule edit audits exist for this case.

---

## Appointment lifecycle

| Field | Value |
|-------|-------|
| Created | 2026-08-04 21:00:31 IST |
| Creator path | `SupportAppointmentController@store` → `SupportAppointmentService::book` (Web) |
| Automation vs manual booking | Customer web form (signed URL), not operator UI assign |
| Preferred slot | 2026-08-05 · evening |
| Status | `scheduled` (never superseded/completed/cancelled) |
| Engineer on appointment row | **N/A** (schema has no assignee) |
| Post-book assign | Intended: `UniversalAssignmentEngine::assignAfterBooking` → `SupportAppointmentSmartAssignmentService` |
| Post-book assign outcome | **Did not persist** |

---

## Automation checklist

| System | Involved? | Finding |
|--------|-----------|---------|
| Ready Queue / shift routing | **Yes — root** | Night admin Shipra at 19:38 |
| Auto assignment (Cashfree grace) | Yes | Led into Ready strategy |
| Appointment smart assignment | Intended at book; **not persisted** | Would not pick admin Shipra anyway |
| Workload balancing | No for Ready path | Ready ignores workload |
| Scheduler / deferred smart | No effect | Case closed; no pending flag change |
| Refund workflow | No | N/A |
| Email intake / closed-case reopen | No | No inbound-email reopen audits |
| Missed-call recovery | Attached only | Did not change owner |
| Closed-appointment repair | No | No repair audits |

---

## Manual actions

| Action | Present? | Who / when |
|--------|----------|------------|
| Manual assign / reassign | **No** | — |
| Manual schedule / edit appointment | **No** | — |
| Close case + reference | **Yes** | Shipra · 20:28:43 |
| Later case/order views | Yes | Shipra (05 Aug afternoon); Ravi view once |

---

## Secondary defect — closed case + scheduled appointment

After web booking on a closed case, production code path is:

1. `SupportAppointmentService::book` (create + notify)
2. `SupportAppointmentBookingWorkflowService::reopenClosedIncidentIfNeeded`
3. `UniversalAssignmentEngine::assignAfterBooking`

For SC25967 / appt #594:

- Step 1 succeeded (row + WhatsApp).
- Steps 2–3 left **no durable trace** (no `service_case.status_changed` closed→open, no `appointment_booking_reopened`, no assign/reassign, `updated_at` unchanged).

So the appointment remained orphaned on a **closed** case still owned by the prior Ready Queue admin. Classifier correctly reports `completed` / not Scheduled while closed.

This matches the class of issues `RepairClosedAppointmentWorkflowCommand` was built to backfill — this case was **not** repaired.

---

## Evidence (production)

- `Order` 25507 / `RD3473671`
- `Incident` 26045 / `SC25967` — `status=closed`, `assigned_to_user_id=3`, `assignment_origin=auto`, `updated_at=2026-08-04 20:28:43`
- `SupportAppointment` 594 — `scheduled`, 2026-08-05 evening
- `AuditLog` **788211** — `service_case.assigned` → user 3, `override_reason=shift_admin`
- `AuditLog` **788967** — status `awaiting_product_details` → `closed` (Shipra)
- `whatsapp_template_dispatches` **15207** — `support_appointment_booked`, `source=support_appointment_web`
- Settings: day admin Avinash (2) 09:00–18:30; **night admin Shipra (3)**; fallback Dileep (4)
- Replay: `resolveEligibleAdmin(2026-08-04 19:38:02)` → Shipra
- Method: read-only SSH + `php artisan tinker` via `tools/config.sh` (`desk.radiumbox.com` / `radium-desk`). No writes.

---

## Files / services involved

| File | Role |
|------|------|
| `app/Support/Assignment/Strategies/ReadyQueueAssignmentStrategy.php` | Ready → shift admin |
| `app/Services/ServiceCaseAssignmentService.php` | `assignToShiftAdminAfterValidation`, night/day window |
| `app/Services/Assignment/ReadyQueueAdminAssignmentService.php` | Eligible admin chain (+ leave gate) |
| `app/Services/Cashfree/CashfreeWebhookProcessorService.php` | Create + `assignOnCreate` |
| `app/Services/SupportAppointmentService.php` | `book` → notify → reopen → assignAfterBooking |
| `app/Services/SupportAppointmentBookingWorkflowService.php` | Reopen closed case on booking |
| `app/Services/Operations/SupportAppointmentSmartAssignmentService.php` | Post-book smart assign (support pool) |
| `app/Support/Assignment/Strategies/AppointmentAssignmentStrategy.php` | Engine bridge for appointments |
| `app/Http/Controllers/SupportAppointmentController.php` | Web booking entry |
| `app/Services/Repairs/Appointments/ClosedAppointmentWorkflowItemHandler.php` | Historical repair for this orphan state |
| `docs/service-case-assignment-entry-points.md` | Entry-point map (#6 Ready, #13 appointment) |

---

## Recommended fix (do not implement in this investigation)

1. **P0 — Operational cleanup for this case**  
   Run closed-appointment workflow repair (or manually reopen + smart-assign / complete appointment if support already done). Decide with ops whether 05 Aug evening slot still needs an open Scheduled case.

2. **P0 — Harden `SupportAppointmentService::book` post-notify path**  
   Ensure reopen + assign are atomic with clear failure audits when booking on closed cases. Today notify can succeed while reopen/assign leave no durable state (this case). Wrap/log reopen failures explicitly; do not rely on HTTP 500 after WhatsApp side effects.

3. **P1 — Ownership policy on post-close booking**  
   When reopening for Tech Support, **clear or replace Ready Queue admin ownership** before/during smart assign so night-shift admins are not left as sticky “Scheduled” owners. Admins are not in `SUPPORT_TEAM_ROLES`.

4. **P2 — Surface orphan closed+scheduled in ops**  
   Alert or repair-queue row when `support_appointments.status=scheduled` and incident is `closed`.

---

## Risk assessment

| Risk | Level | Notes |
|------|-------|-------|
| Wrong engineer did customer work | Medium | Shipra (admin) kept ownership; support pool never received a live Scheduled case |
| Customer confirmation vs internal state | High | Customer got booking WhatsApp; desk case stayed closed |
| Systemic recurrence | High | Any post-close web booking that fails reopen leaves the same orphan pattern |
| Ready Queue night routing itself | Low | Behaved per config; not a mis-pick of day admin |
| Data integrity of this order’s payment/reference | Low | Reference + close completed normally before booking |

---

## Verdict

**Shipra received this appointment’s ownership because she was already the Ready Queue night-shift admin on the case — not because the appointment engine selected her.**

Appointment scheduling attached a `scheduled` row (and customer confirmation) onto that sticky ownership after close, without a persisted reopen/smart-assign cycle.
