# Service Case Auto-Assignment Audit

**Date:** 2026-08-06  
**Environment:** Production (`desk.radiumbox.com`) via SSH + `php artisan tinker`  
**Window:** 2026-08-05 18:00:00 IST → 2026-08-06 14:03:52 IST  
**Snapshot:** ~2026-08-06 14:04 IST  
**Scope:** Production investigation (read-only at capture time) + ownership-preservation fix + Sales Lead assignment fix  
**Canvas:** [`service-case-auto-assignment-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/service-case-auto-assignment-audit.canvas.tsx)

**Related:**
- [`docs/service-case-assignment-entry-points.md`](./service-case-assignment-entry-points.md) — assignment architecture inventory
- [`docs/service-case-assignment-candidate-selection.md`](./service-case-assignment-candidate-selection.md) — eligibility rules
- [`docs/email-intake-phase1-production-audit.md`](./email-intake-phase1-production-audit.md) — same-day email intake audit
- [`docs/sc26557-ready-queue-investigation.md`](./sc26557-ready-queue-investigation.md) — Ready Queue reopen defect (related, out of create-window scope for SC26557 itself)

---

## Bottom line

**1,042** new Service Cases were created in the window. **897** remain auto-assigned, **22** currently show manual ownership, **123** are unassigned.

Assignment is dominated by **Ready Queue shift-admin** (683) and **Support RR / appointment smart** (226). **IRA Learning Rule, Refund workflow, and Sales workflow assigned 0 new cases.**

The critical defect in this window: **all 5 email-created Sales Lead cases are still unassigned** because `assignment.communication_intake_primary_user_id` and `fallback` are both **0**, and `inbound_email.smart_routing_enabled` is **false**.

**Sales Lead fix status:** New creates use **Sales Queue RR → Sales Admin fallback** (never owner null). Communication Intake config is no longer required for Sales Lead. The 5 historical production cases still need one-time reassignment.

---

## Timeline

| When (IST) | Signal |
|---|---|
| 2026-08-05 18:00 | Audit window opens |
| 18:00–18:30 | Day-shift Ready admin still Avinash (day ends 18:30) |
| 18:30→ | Night-shift Ready admin = Shipra (user 3) |
| 19:53 | SC27200 Support assign failure `no_active_support_agents` |
| 20:18 | First email Sales Lead SC27214 created — stays unassigned |
| 2026-08-06 09:00 | Day-shift Ready admin = Avinash (user 2) resumes |
| 09:08 / 09:14 | Missed-call cases SC27322 / SC27332 fail assign (`no_active_support_agents`) |
| 09:25–09:58 | SC27350 Cashfree → closed unassigned → email reopen still unassigned |
| 11:23–13:32 | Four more email Sales Leads created, all unassigned |
| ~14:04 | Snapshot freeze (1,042 cases) |

### Hourly volume

| Hour (IST) | New cases |
|---|---|
| 2026-08-05 18 | 66 |
| 2026-08-05 19 | 62 |
| 2026-08-05 20 | 23 |
| 2026-08-05 21 | 11 |
| 2026-08-05 22 | 4 |
| 2026-08-05 23 | 2 |
| 2026-08-06 00 | 1 |
| 2026-08-06 06 | 3 |
| 2026-08-06 07 | 16 |
| 2026-08-06 08 | 43 |
| 2026-08-06 09 | 110 |
| 2026-08-06 10 | 185 |
| 2026-08-06 11 | 223 |
| 2026-08-06 12 | 158 |
| 2026-08-06 13 | 128 |
| 2026-08-06 14 | 7 |

---

## Summary

| Metric | Count |
|---|---|
| Total new cases | 1042 |
| Auto assigned (current origin auto/appointment, not manual) | 897 |
| Manual assigned (current `assignment_origin=manual`) | 22 |
| Unassigned | 123 |
| Assigned by Ready Queue (`override_reason=shift_admin`) | 683 |
| Assigned by Support workflow (RR + appointment smart) | 226 |
| Assigned by IRA | 0 |
| Assigned by Refund workflow | 0 |
| Assigned by Sales workflow | 0 |
| Hardware order routing | 8 |
| Assignment failures (`no_active_support_agents`) | 3 |
| Currently in Ready Queue (`action_required`) among openish | 95 |

### By source

| Source | Count |
|---|---|
| Cashfree | 851 |
| Call (missed-call recovery) | 186 |
| Email | 5 |
| WhatsApp | 0 |
| Manual UI create | 0 |

### By stored category → classification bucket

| Stored category | Count | Audit classification |
|---|---|---|
| General | 852 | Support (Cashfree / Ready path) |
| Missed Call Recovery | 185 | Support |
| Sales Lead | 5 | Sales |

No Vendor / Docs classifications observed on new cases.

### Created By

| Actor | Count | Bucket |
|---|---|---|
| Ravi (user 1, system actor) | 1041 | System / Auto |
| Jayram Kumar | 1 | Agent |

IRA did not create cases in this window.

### Shift settings (production)

| Setting | Value |
|---|---|
| `assignment.day_shift_admin_user_id` | 2 (Avinash Jha) |
| `assignment.night_shift_admin_user_id` | 3 (Shipra) |
| Day window | 09:00–18:30 IST |
| `assignment.communication_intake_primary_user_id` | **0 (unset)** |
| `assignment.communication_intake_fallback_user_id` | **0 (unset)** |
| `inbound_email.smart_routing_enabled` | **false** |
| `inbound_email.auto_create_service_case` | true |

---

## Routing service breakdown (first assign)

Inference from first `service_case.assigned` / `reassigned` audit (`override_reason`, `assignment_reason`, `assignment_origin`).

| Routing service | Cases |
|---|---|
| ReadyQueueAssignmentStrategy | 683 |
| SupportQueueAssignmentStrategy/RR | 211 |
| UnassignedNoLog | 119 |
| SupportAppointmentSmartAssignmentService | 15 |
| HardwareOrderRouting | 8 |
| Failure:no_active_support_agents | 3 |
| Manual | 1 |
| AssignedWithoutAudit | 1 |
| GracePending | 1 |

| Source × routing | Count |
|---|---|
| cashfree|ReadyQueueAssignmentStrategy | 683 |
| call|SupportQueueAssignmentStrategy/RR | 183 |
| cashfree|UnassignedNoLog | 114 |
| cashfree|SupportQueueAssignmentStrategy/RR | 28 |
| cashfree|SupportAppointmentSmartAssignmentService | 15 |
| cashfree|HardwareOrderRouting | 7 |
| email|UnassignedNoLog | 5 |
| call|Failure:no_active_support_agents | 2 |
| cashfree|Manual | 1 |
| cashfree|Failure:no_active_support_agents | 1 |
| cashfree|AssignedWithoutAudit | 1 |
| call|HardwareOrderRouting | 1 |
| cashfree|GracePending | 1 |


---

## Engineer distribution

| User | Cases | Primary assignment source | Case sources |
|---|---|---|---|
| Avinash Jha | 588 | Ready Queue | cashfree 556, call 32 |
| Shipra | 142 | Ready Queue | cashfree 142 |
| Jayram Kumar | 88 | Support | cashfree 8, call 80 |
| Vanshika Baniwal | 61 | Support | cashfree 11, call 50 |
| Gaurav Kumar | 24 | Support | cashfree 7, call 17 |
| Sumit Kumar | 9 | Hardware | cashfree 8, call 1 |
| Sushant Shetty | 5 | Support | cashfree 1, call 4 |
| Jyotsana Baranwal | 2 | Support | cashfree 2 |

---

## Assignment quality

| Finding | Count | Notes |
|---|---|---|
| Support → Ready ownership steal | 42 | First Support RR, then `shift_admin` Ready override |
| Ready → Manual reassign | 7 | Operators correcting shift-admin ownership |
| Reassign loops (≥3 hops) | 5 | SC27387, SC27392, SC27398, SC27673, SC27818 |
| Sticky Ready ownership | Structural | Day→Avinash / Night→Shipra by config; 683 Ready assigns |

### Wrong / risky assignments

1. **Email Sales Leads unassigned (5)** — should have gone to Sales / communication intake owner; went nowhere.
2. **Missed-call failures (2 open)** — Support intake expected; failed with `no_active_support_agents` at 09:08 / 09:14 IST.
3. **Support→Ready steals (42)** — Support engineer briefly owns case, then Ready validation reassigns to shift admin. Looks like sticky admin ownership overriding Support RR. **Fixed:** Ready Queue must not overwrite human ownership for Support / Appointment / Refund / Sales / Manual origins (see [Ownership preservation fix](#ownership-preservation-fix)).
4. **SC27350** — closed unassigned, then email reopen left owner null (intake unconfigured) despite live customer emails.

### Unassigned breakdown (123)

| Cohort | Count | Expected? | Actual |
|---|---|---|---|
| Cashfree `awaiting_product_details` | 84 | Yes — wait for identity / manual correction | Grace started; `validation_failed` → `waiting_manual_correction`; no Ready assign yet |
| Cashfree `closed` never assigned | 31 | Ambiguous | Closed after automation WhatsApp/email without ever assigning |
| Email Sales Lead `open` | 5 | **No** — should assign | Intake primary/fallback unset |
| Call missed-call `open` | 2 | **No** — Support RR | `no_active_support_agents` |
| Cashfree `open` (SC27350) | 1 | **No** after reopen | Reopened by email with null owner |

---

## Special attention — every email-created Service Case

| Case | Created | From | Subject | Customer | Order | Class | Assignee | Customer match | Auto-assign OK? |
|---|---|---|---|---|---|---|---|---|---|
| SC27214 | 2026-08-05 20:18:24 | naukritalentcloud@naukri.com | Naukri’s Hiring Outlook Survey July-Dec 2026 / You | Team Naukri | INQ-SC27214 | possible_sales_lead | UNASSIGNED | Inquiry INQ (no prior RD match) | No — intake unset |
| SC27689 | 2026-08-06 11:23:04 | aptitudeedutechskills@gmail.com | Fwd: Your Order at RD SERVICE ONLINE is successful | APTITUDE EDUTECH SKILLS | INQ-SC27689 | possible_sales_lead | UNASSIGNED | Inquiry INQ (no prior RD match) | No — intake unset |
| SC27794 | 2026-08-06 11:53:06 | safanashaik7@gmail.com | Update device | Safana Shaik | INQ-SC27794 | possible_sales_lead | UNASSIGNED | Inquiry INQ (no prior RD match) | No — intake unset |
| SC27943 | 2026-08-06 12:41:10 | sabariraj307@gmail.com | Queries | Sabari | INQ-SC27943 | possible_sales_lead | UNASSIGNED | Inquiry INQ (no prior RD match) | No — intake unset |
| SC28059 | 2026-08-06 13:32:06 | pranavkumara90@gmail.com | — | Pranav Kumara | INQ-SC28059 | possible_sales_lead | UNASSIGNED | Inquiry INQ (no prior RD match) | No — intake unset |

### Why each email case was created

All five hit `IncomingEmailServiceCaseCreateService::ensureForUnknownCustomer` after classification `possible_sales_lead`:

1. No prior order matched the from-email → new **INQ-*** inquiry order + Service Case.
2. `assignOnCreate: false` on create.
3. `routeLinkedEmail` → `assignCommunicationIntake` ran.
4. Primary and fallback user IDs are **0** → no assignee; **no assignment audit row**.
5. Smart routing (`IncomingEmailSmartRoutingAssignmentService` sales RR) is **disabled** in production.

| Case | Why created (subject signal) | Match | Assign |
|---|---|---|---|
| SC27214 | Naukri survey mail classified sales lead | New inquiry | Failed (unset intake) |
| SC27689 | Fwd order-success mail | New inquiry | Failed |
| SC27794 | "Update device" | New inquiry | Failed |
| SC27943 | "Queries" | New inquiry | Failed |
| SC28059 | Empty subject | New inquiry | Failed |

---

## Unassigned open cases (action list)

| Case | Created | Source | Category | Order | Customer | Why unassigned |
|---|---|---|---|---|---|---|
| SC27214 | 2026-08-05 20:18:24 | email | Sales Lead | INQ-SC27214 | Team Naukri | UnassignedNoLog |
| SC27322 | 2026-08-06 09:08:17 | call | Missed Call Recovery | INQ-SC27322 | — | Failure:no_active_support_agents |
| SC27332 | 2026-08-06 09:14:46 | call | Missed Call Recovery | INQ-SC27332 | — | Failure:no_active_support_agents |
| SC27350 | 2026-08-06 09:25:54 | cashfree | General | RD3475785 | MMS | UnassignedNoLog |
| SC27689 | 2026-08-06 11:23:04 | email | Sales Lead | INQ-SC27689 | APTITUDE EDUTECH SKILLS | UnassignedNoLog |
| SC27794 | 2026-08-06 11:53:06 | email | Sales Lead | INQ-SC27794 | Safana Shaik | UnassignedNoLog |
| SC27943 | 2026-08-06 12:41:10 | email | Sales Lead | INQ-SC27943 | Sabari | UnassignedNoLog |
| SC28059 | 2026-08-06 13:32:06 | email | Sales Lead | INQ-SC28059 | Pranav Kumara | UnassignedNoLog |

---

## Assignment failures

| Case | Created | Source | Status | Expected | Actual |
|---|---|---|---|---|---|
| SC27200 | 2026-08-05 19:53:12 | cashfree | awaiting_product_details | Support RR assign | Failure:no_active_support_agents |
| SC27322 | 2026-08-06 09:08:17 | call | open | Support RR assign | Failure:no_active_support_agents |
| SC27332 | 2026-08-06 09:14:46 | call | open | Support RR assign | Failure:no_active_support_agents |

---

## Case inventory notes

Full per-case inventory (1,042 rows) with source, customer, order, created-by bucket, classification, assignee, auto/manual flag, routing service, and reassignment hops is embedded in the canvas (filterable; first 250 rows shown at a time).

Cohort counts for the markdown:

### Non-Cashfree cases (191)

| Case | Created | Source | Customer | Order | Assignee | Origin | Routing | Status |
|---|---|---|---|---|---|---|---|---|
| SC27214 | 08-05 20:18 | email | Team Naukri | INQ-SC27214 | — | auto | Unassigned | open |
| SC27314 | 08-06 09:04 | call | SRINIVAS REDDY V | RD3475799 | Jayram Kumar | auto | Support RR | closed |
| SC27322 | 08-06 09:08 | call | — | INQ-SC27322 | — | auto | Fail:no agents | open |
| SC27332 | 08-06 09:14 | call | — | INQ-SC27332 | — | auto | Fail:no agents | open |
| SC27366 | 08-06 09:37 | call | — | INQ-SC27366 | Jayram Kumar | auto | Support RR | open |
| SC27371 | 08-06 09:40 | call | — | INQ-SC27371 | Jayram Kumar | auto | Support RR | resolved |
| SC27378 | 08-06 09:43 | call | — | INQ-SC27378 | Jayram Kumar | auto | Support RR | open |
| SC27380 | 08-06 09:43 | call | — | INQ-SC27380 | Jayram Kumar | auto | Support RR | resolved |
| SC27384 | 08-06 09:45 | call | — | INQ-SC27384 | Jayram Kumar | auto | Support RR | open |
| SC27393 | 08-06 09:48 | call | — | INQ-SC27393 | Jayram Kumar | auto | Support RR | resolved |
| SC27398 | 08-06 09:50 | call | ashok kumar sharma | rd3422143 | Jayram Kumar | manual | Support RR | closed |
| SC27410 | 08-06 09:54 | call | — | INQ-SC27410 | Jayram Kumar | auto | Support RR | resolved |
| SC27411 | 08-06 09:54 | call | — | INQ-SC27411 | Jayram Kumar | auto | Support RR | open |
| SC27415 | 08-06 09:56 | call | — | INQ-SC27415 | Jayram Kumar | auto | Support RR | open |
| SC27421 | 08-06 10:00 | call | — | INQ-SC27421 | Sushant Shetty | auto | Support RR | resolved |
| SC27424 | 08-06 10:01 | call | DEEPAK KUMAR | RD3475841 | Avinash Jha | auto | Support RR | open |
| SC27426 | 08-06 10:01 | call | — | INQ-SC27426 | Sushant Shetty | auto | Support RR | open |
| SC27435 | 08-06 10:04 | call | — | INQ-SC27435 | Jayram Kumar | auto | Support RR | open |
| SC27438 | 08-06 10:05 | call | dto rayachoty | RD3422481 | Avinash Jha | auto | Support RR | resolved |
| SC27441 | 08-06 10:06 | call | — | INQ-SC27441 | Jayram Kumar | auto | Support RR | resolved |
| SC27443 | 08-06 10:06 | call | — | INQ-SC27443 | Sushant Shetty | auto | Support RR | open |
| SC27445 | 08-06 10:07 | call | — | INQ-SC27445 | Jayram Kumar | auto | Support RR | open |
| SC27446 | 08-06 10:09 | call | — | INQ-SC27446 | Sushant Shetty | auto | Support RR | open |
| SC27456 | 08-06 10:14 | call | Ashok Kumar Bankar | RD3473895 | Avinash Jha | auto | Support RR | open |
| SC27459 | 08-06 10:15 | call | — | INQ-SC27459 | Jayram Kumar | auto | Support RR | resolved |
| SC27462 | 08-06 10:16 | call | — | INQ-SC27462 | Jayram Kumar | auto | Support RR | open |
| SC27463 | 08-06 10:16 | call | — | INQ-SC27463 | Jayram Kumar | auto | Support RR | open |
| SC27470 | 08-06 10:18 | call | — | INQ-SC27470 | Jayram Kumar | auto | Support RR | open |
| SC27474 | 08-06 10:20 | call | — | INQ-SC27474 | Jayram Kumar | auto | Support RR | resolved |
| SC27480 | 08-06 10:21 | call | — | INQ-SC27480 | Jayram Kumar | auto | Support RR | open |
| SC27487 | 08-06 10:25 | call | — | INQ-SC27487 | Jayram Kumar | auto | Support RR | resolved |
| SC27491 | 08-06 10:26 | call | — | INQ-SC26927 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27493 | 08-06 10:27 | call | — | INQ-SC27459 | Jayram Kumar | auto | Support RR | open |
| SC27504 | 08-06 10:32 | call | — | INQ-SC27441 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27509 | 08-06 10:33 | call | — | INQ-SC27509 | Jayram Kumar | auto | Support RR | open |
| SC27511 | 08-06 10:33 | call | — | INQ-SC27511 | Vanshika Baniwal | auto | Support RR | closed |
| SC27520 | 08-06 10:35 | call | — | INQ-SC27520 | Jayram Kumar | auto | Support RR | open |
| SC27529 | 08-06 10:37 | call | — | INQ-SC27487 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27540 | 08-06 10:40 | call | — | INQ-SC27540 | Jayram Kumar | auto | Support RR | open |
| SC27542 | 08-06 10:42 | call | — | INQ-SC27542 | Vanshika Baniwal | auto | Support RR | open |
| SC27552 | 08-06 10:43 | call | — | INQ-SC27552 | Jayram Kumar | auto | Support RR | resolved |
| SC27554 | 08-06 10:45 | call | — | INQ-SC27554 | Vanshika Baniwal | auto | Support RR | open |
| SC27556 | 08-06 10:45 | call | — | INQ-SC27556 | Jayram Kumar | auto | Support RR | open |
| SC27558 | 08-06 10:45 | call | SANJAY JAMATIA | RD3475756 | Avinash Jha | auto | Support RR | open |
| SC27561 | 08-06 10:46 | call | RAJEEV KUMAR | RD3475068 | Avinash Jha | auto | Support RR | resolved |
| SC27566 | 08-06 10:48 | call | SRINIVASA RAO PALLAPOTHU | RD3437880 | Avinash Jha | auto | Support RR | open |
| SC27570 | 08-06 10:48 | call | PankajGogoi | RDE285493 | Sumit Kumar | auto | HardwareOrderRouting | open |
| SC27571 | 08-06 10:49 | call | — | INQ-SC27571 | Jayram Kumar | auto | Support RR | resolved |
| SC27575 | 08-06 10:51 | call | — | INQ-SC27575 | Jayram Kumar | auto | Support RR | open |
| SC27590 | 08-06 10:56 | call | Akshay Somnath | RD3475478 | Avinash Jha | auto | Support RR | open |
| SC27591 | 08-06 10:56 | call | — | INQ-SC27591 | Jayram Kumar | auto | Support RR | open |
| SC27592 | 08-06 10:56 | call | — | INQ-SC27592 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27606 | 08-06 11:00 | call | — | INQ-SC27606 | Jayram Kumar | auto | Support RR | open |
| SC27609 | 08-06 11:00 | call | — | INQ-SC27609 | Vanshika Baniwal | auto | Support RR | open |
| SC27617 | 08-06 11:01 | call | — | INQ-SC27617 | Vanshika Baniwal | auto | Support RR | open |
| SC27621 | 08-06 11:02 | call | — | INQ-SC27621 | Vanshika Baniwal | auto | Support RR | open |
| SC27628 | 08-06 11:04 | call | — | INQ-SC27628 | Jayram Kumar | auto | Support RR | open |
| SC27631 | 08-06 11:05 | call | — | INQ-SC27631 | Vanshika Baniwal | auto | Support RR | open |
| SC27632 | 08-06 11:05 | call | HAFIZ RAZA | RD3472410 | Avinash Jha | auto | Support RR | open |
| SC27633 | 08-06 11:05 | call | mahdevbhai  modabhai ver | RD3469158 | Avinash Jha | auto | Support RR | open |
| SC27635 | 08-06 11:07 | call | — | INQ-SC26212 | Jayram Kumar | auto | Support RR | open |
| SC27638 | 08-06 11:08 | call | — | INQ-SC27638 | Vanshika Baniwal | auto | Support RR | open |
| SC27640 | 08-06 11:08 | call | — | INQ-SC27640 | Jayram Kumar | auto | Support RR | open |
| SC27644 | 08-06 11:10 | call | — | INQ-SC27644 | Vanshika Baniwal | auto | Support RR | open |
| SC27650 | 08-06 11:11 | call | — | INQ-SC27650 | Vanshika Baniwal | auto | Support RR | open |
| SC27651 | 08-06 11:11 | call | — | INQ-SC27651 | Jayram Kumar | auto | Support RR | open |
| SC27652 | 08-06 11:11 | call | — | INQ-SC27652 | Vanshika Baniwal | auto | Support RR | open |
| SC27655 | 08-06 11:12 | call | — | INQ-SC27592 | Jayram Kumar | auto | Support RR | resolved |
| SC27656 | 08-06 11:12 | call | — | INQ-SC27656 | Vanshika Baniwal | auto | Support RR | open |
| SC27661 | 08-06 11:13 | call | PARITOSH KARNA | RD3475874 | Avinash Jha | auto | Support RR | open |
| SC27676 | 08-06 11:20 | call | — | INQ-SC27676 | Jayram Kumar | auto | Support RR | open |
| SC27678 | 08-06 11:20 | call | — | INQ-SC27678 | Vanshika Baniwal | auto | Support RR | open |
| SC27679 | 08-06 11:20 | call | — | INQ-SC27679 | Jayram Kumar | auto | Support RR | resolved |
| SC27680 | 08-06 11:20 | call | — | INQ-SC27680 | Vanshika Baniwal | auto | Support RR | open |
| SC27681 | 08-06 11:20 | call | ASHOK NAMDEV BHOSALE | RD3476522 | Jayram Kumar | auto | Support RR | closed |
| SC27682 | 08-06 11:20 | call | — | INQ-SC27682 | Vanshika Baniwal | auto | Support RR | open |
| SC27684 | 08-06 11:22 | call | — | INQ-SC27684 | Jayram Kumar | auto | Support RR | open |
| SC27686 | 08-06 11:22 | call | — | INQ-SC27686 | Vanshika Baniwal | auto | Support RR | open |
| SC27689 | 08-06 11:23 | email | APTITUDE EDUTECH SKILLS | INQ-SC27689 | — | auto | Unassigned | open |
| SC27702 | 08-06 11:26 | call | — | INQ-SC27702 | Jayram Kumar | auto | Support RR | open |
| SC27715 | 08-06 11:29 | call | — | INQ-SC27715 | Vanshika Baniwal | auto | Support RR | open |
| SC27716 | 08-06 11:29 | call | — | INQ-SC27716 | Jayram Kumar | auto | Support RR | open |
| SC27736 | 08-06 11:35 | call | — | INQ-SC27736 | Jayram Kumar | auto | Support RR | open |
| SC27741 | 08-06 11:38 | call | SAI HOSPITAL KARAD | RD3444054 | Avinash Jha | auto | Support RR | open |
| SC27743 | 08-06 11:39 | call | ASIM DAS | RD3476231 | Avinash Jha | auto | Support RR | open |
| SC27744 | 08-06 11:39 | call | — | INQ-SC27744 | Vanshika Baniwal | auto | Support RR | open |
| SC27747 | 08-06 11:39 | call | Gangoti Bhujangarao | RD3476111 | Avinash Jha | auto | Support RR | open |
| SC27752 | 08-06 11:40 | call | — | INQ-SC27752 | Vanshika Baniwal | auto | Support RR | open |
| SC27757 | 08-06 11:42 | call | CHITRAREKHA BARMAN | RD3472563 | Avinash Jha | auto | Support RR | resolved |
| SC27760 | 08-06 11:42 | call | SHAMSHERA SINGH | RD3476228 | Avinash Jha | auto | Support RR | open |
| SC27771 | 08-06 11:46 | call | UMASHANKAR SAH | RD3475032 | Avinash Jha | auto | Support RR | open |
| SC27774 | 08-06 11:46 | call | SADSHILL BHARAT GAS | RD3475033 | Avinash Jha | auto | Support RR | open |
| SC27778 | 08-06 11:47 | call | — | INQ-SC27778 | Vanshika Baniwal | auto | Support RR | open |
| SC27782 | 08-06 11:47 | call | — | INQ-SC27782 | Jayram Kumar | auto | Support RR | open |
| SC27787 | 08-06 11:51 | call | — | INQ-SC27787 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27791 | 08-06 11:52 | call | — | INQ-SC27791 | Jayram Kumar | auto | Support RR | open |
| SC27792 | 08-06 11:52 | call | — | INQ-SC27792 | Vanshika Baniwal | auto | Support RR | open |
| SC27794 | 08-06 11:53 | email | Safana Shaik | INQ-SC27794 | — | auto | Unassigned | open |
| SC27795 | 08-06 11:53 | call | — | INQ-SC27795 | Jayram Kumar | auto | Support RR | resolved |
| SC27799 | 08-06 11:54 | call | — | INQ-SC26640 | Vanshika Baniwal | auto | Support RR | open |
| SC27801 | 08-06 11:55 | call | — | INQ-SC27801 | Vanshika Baniwal | auto | Support RR | open |
| SC27807 | 08-06 11:56 | call | — | INQ-SC27807 | Jayram Kumar | auto | Support RR | open |
| SC27811 | 08-06 11:57 | call | VINAY JEERANKALAGI | RD3474920 | Avinash Jha | auto | Support RR | resolved |
| SC27816 | 08-06 11:58 | call | santosh | RD3476274 | Avinash Jha | auto | Support RR | open |
| SC27817 | 08-06 11:58 | call | — | INQ-SC27817 | Vanshika Baniwal | auto | Support RR | open |
| SC27820 | 08-06 11:58 | call | Vidhan GUPTA | RD3476491 | Jayram Kumar | auto | Support RR | closed |
| SC27822 | 08-06 11:59 | call | — | INQ-SC27822 | Vanshika Baniwal | auto | Support RR | open |
| SC27825 | 08-06 11:59 | call | DALPAT MALI | RD3476287 | Avinash Jha | auto | Support RR | open |
| SC27827 | 08-06 11:59 | call | CHC GILUND | RD3475957 | Avinash Jha | auto | Support RR | open |
| SC27832 | 08-06 12:00 | call | — | INQ-SC27832 | Jayram Kumar | auto | Support RR | open |
| SC27833 | 08-06 12:01 | call | — | INQ-SC27833 | Vanshika Baniwal | auto | Support RR | open |
| SC27843 | 08-06 12:04 | call | — | INQ-SC27843 | Jayram Kumar | auto | Support RR | resolved |
| SC27846 | 08-06 12:05 | call | — | INQ-SC27846 | Vanshika Baniwal | auto | Support RR | open |
| SC27849 | 08-06 12:06 | call | — | INQ-SC27849 | Jayram Kumar | auto | Support RR | open |
| SC27850 | 08-06 12:07 | call | — | INQ-SC27850 | Vanshika Baniwal | auto | Support RR | open |
| SC27855 | 08-06 12:08 | call | — | INQ-SC27855 | Jayram Kumar | auto | Support RR | open |
| SC27856 | 08-06 12:08 | call | — | INQ-SC27856 | Vanshika Baniwal | auto | Support RR | open |
| SC27857 | 08-06 12:09 | call | — | INQ-SC27857 | Jayram Kumar | auto | Support RR | open |
| SC27865 | 08-06 12:11 | call | AAkash Patil | RD3476064 | Avinash Jha | auto | Support RR | resolved |
| SC27867 | 08-06 12:11 | call | — | INQ-SC27867 | Jayram Kumar | auto | Support RR | open |
| SC27869 | 08-06 12:12 | call | — | INQ-SC27869 | Vanshika Baniwal | auto | Support RR | open |
| SC27872 | 08-06 12:13 | call | RAJ EYE HOSPITAL | RD3476206 | Avinash Jha | auto | Support RR | resolved |
| SC27886 | 08-06 12:19 | call | — | INQ-SC27886 | Jayram Kumar | auto | Support RR | resolved |
| SC27887 | 08-06 12:20 | call | — | INQ-SC27887 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27891 | 08-06 12:20 | call | — | INQ-SC27891 | Jayram Kumar | auto | Support RR | resolved |
| SC27892 | 08-06 12:20 | call | — | INQ-SC27892 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27895 | 08-06 12:21 | call | — | INQ-SC27895 | Jayram Kumar | auto | Support RR | open |
| SC27897 | 08-06 12:23 | call | — | INQ-SC27897 | Vanshika Baniwal | auto | Support RR | open |
| SC27904 | 08-06 12:24 | call | RAJEEV KUMAR | RD3475068 | Avinash Jha | auto | Support RR | open |
| SC27915 | 08-06 12:27 | call | — | INQ-SC27915 | Vanshika Baniwal | auto | Support RR | open |
| SC27917 | 08-06 12:27 | call | ARVIND KUMAR AGRAWAL | RD3475767 | Avinash Jha | auto | Support RR | open |
| SC27920 | 08-06 12:29 | call | — | INQ-SC27920 | Jayram Kumar | auto | Support RR | resolved |
| SC27937 | 08-06 12:38 | call | — | INQ-SC27920 | Jayram Kumar | auto | Support RR | resolved |
| SC27943 | 08-06 12:41 | email | Sabari | INQ-SC27943 | — | auto | Unassigned | open |
| SC27944 | 08-06 12:41 | call | — | INQ-SC27944 | Vanshika Baniwal | auto | Support RR | open |
| SC27945 | 08-06 12:41 | call | — | INQ-SC27945 | Jayram Kumar | auto | Support RR | resolved |
| SC27946 | 08-06 12:41 | call | — | INQ-SC27946 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27954 | 08-06 12:44 | call | — | INQ-SC27954 | Vanshika Baniwal | auto | Support RR | resolved |
| SC27955 | 08-06 12:44 | call | Ramesh | RD3475633 | Avinash Jha | auto | Support RR | open |
| SC27963 | 08-06 12:47 | call | pankaj yogi | RD3435073 | Avinash Jha | auto | Support RR | open |
| SC27964 | 08-06 12:48 | call | — | INQ-SC27946 | Jayram Kumar | auto | Support RR | open |
| SC27967 | 08-06 12:49 | call | — | INQ-SC27891 | Vanshika Baniwal | auto | Support RR | open |
| SC27971 | 08-06 12:50 | call | — | INQ-SC27971 | Jayram Kumar | auto | Support RR | open |
| SC27983 | 08-06 12:58 | call | — | INQ-SC27983 | Gaurav Kumar | auto | Support RR | resolved |
| SC27994 | 08-06 13:02 | call | — | INQ-SC27994 | Jayram Kumar | auto | Support RR | open |
| SC27997 | 08-06 13:05 | call | — | INQ-SC27997 | Gaurav Kumar | auto | Support RR | open |
| SC27998 | 08-06 13:05 | call | — | INQ-SC27887 | Jayram Kumar | auto | Support RR | resolved |
| SC28006 | 08-06 13:10 | call | — | INQ-SC28006 | Gaurav Kumar | auto | Support RR | open |
| SC28009 | 08-06 13:11 | call | — | INQ-SC27945 | Gaurav Kumar | auto | Support RR | resolved |
| SC28016 | 08-06 13:13 | call | — | INQ-SC28016 | Jayram Kumar | auto | Support RR | open |
| SC28017 | 08-06 13:14 | call | — | INQ-SC28017 | Gaurav Kumar | auto | Support RR | open |
| SC28018 | 08-06 13:14 | call | — | INQ-SC27887 | Jayram Kumar | auto | Support RR | open |
| SC28021 | 08-06 13:15 | call | Suraj Kumar | RD3454444 | Avinash Jha | auto | Support RR | open |
| SC28022 | 08-06 13:16 | call | — | INQ-SC28022 | Jayram Kumar | auto | Support RR | open |
| SC28024 | 08-06 13:16 | call | — | INQ-SC28024 | Gaurav Kumar | auto | Support RR | open |
| SC28025 | 08-06 13:16 | call | — | INQ-SC28025 | Jayram Kumar | auto | Support RR | open |
| SC28028 | 08-06 13:17 | call | — | INQ-SC28028 | Gaurav Kumar | auto | Support RR | resolved |
| SC28032 | 08-06 13:19 | call | — | INQ-SC28028 | Jayram Kumar | auto | Support RR | open |
| SC28033 | 08-06 13:19 | call | — | INQ-SC28033 | Gaurav Kumar | auto | Support RR | open |
| SC28035 | 08-06 13:21 | call | — | INQ-SC28035 | Jayram Kumar | auto | Support RR | resolved |
| SC28038 | 08-06 13:21 | call | — | INQ-SC28038 | Gaurav Kumar | auto | Support RR | open |
| SC28040 | 08-06 13:21 | call | — | INQ-SC28040 | Jayram Kumar | auto | Support RR | resolved |
| SC28045 | 08-06 13:25 | call | Prashant Ramesh Magare | RD3463390 | Avinash Jha | auto | Support RR | resolved |
| SC28048 | 08-06 13:26 | call | — | INQ-SC28048 | Jayram Kumar | auto | Support RR | open |
| SC28049 | 08-06 13:27 | call | — | INQ-SC28049 | Gaurav Kumar | auto | Support RR | open |
| SC28051 | 08-06 13:28 | call | — | INQ-SC28051 | Jayram Kumar | auto | Support RR | open |
| SC28052 | 08-06 13:28 | call | — | INQ-SC28052 | Gaurav Kumar | auto | Support RR | resolved |
| SC28054 | 08-06 13:31 | call | — | INQ-SC28054 | Vanshika Baniwal | auto | Support RR | resolved |
| SC28055 | 08-06 13:31 | call | — | INQ-SC28055 | Jayram Kumar | auto | Support RR | open |
| SC28056 | 08-06 13:31 | call | — | INQ-SC28056 | Gaurav Kumar | auto | Support RR | resolved |
| SC28057 | 08-06 13:31 | call | Prashant Ramesh Magare | RD3463390 | Avinash Jha | auto | Support RR | open |
| SC28059 | 08-06 13:32 | email | Pranav Kumara | INQ-SC28059 | — | auto | Unassigned | open |
| SC28060 | 08-06 13:32 | call | — | INQ-SC28060 | Jayram Kumar | auto | Support RR | open |
| SC28062 | 08-06 13:34 | call | AJAY JAISWAL | RD3476271 | Avinash Jha | auto | Support RR | resolved |
| SC28064 | 08-06 13:34 | call | — | INQ-SC28035 | Vanshika Baniwal | auto | Support RR | open |
| SC28066 | 08-06 13:35 | call | — | INQ-SC28066 | Jayram Kumar | auto | Support RR | open |
| SC28070 | 08-06 13:38 | call | — | INQ-SC28070 | Gaurav Kumar | auto | Support RR | open |
| SC28084 | 08-06 13:45 | call | — | INQ-SC28056 | Jayram Kumar | auto | Support RR | open |
| SC28085 | 08-06 13:45 | call | — | INQ-SC28085 | Gaurav Kumar | auto | Support RR | open |
| SC28089 | 08-06 13:46 | call | — | INQ-SC28089 | Vanshika Baniwal | auto | Support RR | open |
| SC28091 | 08-06 13:46 | call | — | INQ-SC28091 | Jayram Kumar | auto | Support RR | open |
| SC28095 | 08-06 13:49 | call | — | INQ-SC28095 | Gaurav Kumar | auto | Support RR | open |
| SC28097 | 08-06 13:51 | call | — | INQ-SC28097 | Vanshika Baniwal | auto | Support RR | open |
| SC28098 | 08-06 13:52 | call | — | INQ-SC28098 | Jayram Kumar | auto | Support RR | open |
| SC28099 | 08-06 13:52 | call | — | INQ-SC27983 | Gaurav Kumar | auto | Support RR | open |
| SC28104 | 08-06 13:53 | call | SAURABHA KUMAR | RD3476474 | Avinash Jha | auto | Support RR | open |
| SC28108 | 08-06 13:54 | call | — | INQ-SC28108 | Jayram Kumar | auto | Support RR | open |
| SC28109 | 08-06 13:55 | call | — | INQ-SC28109 | Gaurav Kumar | auto | Support RR | open |
| SC28110 | 08-06 13:56 | call | — | INQ-SC28110 | Vanshika Baniwal | auto | Support RR | open |
| SC28119 | 08-06 14:01 | call | — | INQ-SC28119 | Vanshika Baniwal | auto | Support RR | open |
| SC28121 | 08-06 14:03 | call | — | INQ-SC28121 | Vanshika Baniwal | auto | Support RR | open |

### Currently manual origin (22)

| Case | Source | Assignee | Status | Order | First routing | Hops |
|---|---|---|---|---|---|---|
| SC27120 | cashfree | Avinash Jha | closed | RD3475380 | Manual | 2 |
| SC27163 | cashfree | Jayram Kumar | awaiting_product_details | RD3475445 | Support RR | 2 |
| SC27234 | cashfree | Sumit Kumar | awaiting_product_details | RD3475580 | Support RR | 2 |
| SC27242 | cashfree | Sushant Shetty | awaiting_product_details | RD3475604 | Support RR | 2 |
| SC27387 | cashfree | Avinash Jha | closed | RD3475849 | Ready | 3 |
| SC27392 | cashfree | Avinash Jha | closed | RD3475855 | Support RR | 3 |
| SC27398 | call | Jayram Kumar | closed | rd3422143 | Support RR | 3 |
| SC27405 | cashfree | Avinash Jha | closed | RD3475867 | Support RR | 2 |
| SC27533 | cashfree | Avinash Jha | closed | RD3476040 | Support RR | 2 |
| SC27584 | cashfree | Avinash Jha | closed | RD3476099 | Appt Smart | 2 |
| SC27658 | cashfree | Avinash Jha | closed | RD3476184 | Support RR | 2 |
| SC27663 | cashfree | Avinash Jha | closed | RD3476159 | Appt Smart | 2 |
| SC27667 | cashfree | Avinash Jha | closed | RD3476199 | Appt Smart | 2 |
| SC27673 | cashfree | Avinash Jha | closed | RD3476204 | Ready | 3 |
| SC27693 | cashfree | Gaurav Kumar | awaiting_product_details | RD3476232 | Ready | 2 |
| SC27745 | cashfree | Avinash Jha | closed | RD3476303 | Appt Smart | 2 |
| SC27756 | cashfree | Avinash Jha | closed | RD3476318 | Appt Smart | 2 |
| SC27818 | cashfree | Avinash Jha | closed | RD3476400 | Support RR | 3 |
| SC27821 | cashfree | Avinash Jha | closed | RD3476407 | Appt Smart | 2 |
| SC27851 | cashfree | Gaurav Kumar | awaiting_product_details | RD3476441 | Ready | 2 |
| SC27888 | cashfree | Avinash Jha | closed | RD3476492 | Appt Smart | 2 |
| SC27905 | cashfree | Avinash Jha | closed | RD3476516 | Support RR | 2 |

---

## Root causes

1. **Email Sales Lead auto-assign dead** — Communication intake primary/fallback were both `0`, and Sales Lead create path depended on them. **Fixed:** Sales Lead now uses Sales RR → Sales Admin fallback (independent of intake config / Smart Routing). Non-sales email reopens with null owner (e.g. SC27350 General) still use Communication Intake.
2. **Sales smart routing off** — `inbound_email.smart_routing_enabled=false` meant sales RR never ran for unmatched mail. **Mitigated for Sales Lead creates:** auto-create path now uses the same Sales RR strategy even when Smart Routing is off.
3. **Support agent availability gaps** — At least three creates logged `no_active_support_agents` (evening Cashfree path + morning missed calls).
4. **Ready Queue concentrates on shift admins** — By design (`day_shift_admin` / `night_shift_admin`), but 683 first-assigns + 42 Support→Ready steals create sticky admin ownership and manual correction load.
5. **Cashfree incomplete identity** — 84 unassigned `awaiting_product_details` are waiting on validation/manual correction; not an assign-engine miss for Ready-eligible cases.
6. **Closed-without-owner automation** — 31 Cashfree cases closed after WhatsApp/email automation without ever receiving an assignee.

---

## Recommendations

1. ~~Configure Communication Intake for Sales Lead~~ — **superseded** (see [Sales Lead assignment fix](#sales-lead-assignment-fix)).
2. Configure `assignment.inbound_email_sales_round_robin_user_ids` (and optionally `assignment.sales_lead_handler_user_id`) so Sales RR is preferred before shift-admin fallback.
3. Review **Support availability** around 09:00 IST (missed-call failures) and evening Support fallback for non-Ready Cashfree.
4. ~~Measure whether **Support→Ready reassignment** should preserve Support ownership~~ — **done** (see [Ownership preservation fix](#ownership-preservation-fix)).
5. Triage the **5 open Sales Lead** cases and **2 open missed-call** failures manually today (historical; create-path fixed going forward).
6. Keep Ready shift-admin settings explicit; volume on Avinash/Shipra is expected with current config but may need capacity planning.

---

## Sales Lead assignment fix

**Date applied:** 2026-08-06  
**Defect addressed:** All 5 email-created Sales Leads left unassigned when Communication Intake primary/fallback were unset and Smart Routing was off.

### Strategy

Unknown Customer + `possible_sales_lead` (category **Sales Lead**):

1. **Sales Queue Round Robin** (`assignment.inbound_email_sales_round_robin_user_ids`)
2. If RR unavailable (empty pool / all inactive) → **Sales Admin** (`assignment.sales_lead_handler_user_id` → Ready Queue admin → shift admin → actor)
3. **Never leave owner null**

| Smart Routing | IRA Memory / learning owner | Assignment |
|---|---|---|
| Enabled | Present | May override RR (`decision_source=ira_memory`) |
| Enabled | Absent | Sales RR → Sales Admin fallback |
| Disabled | Ignored | Sales RR → Sales Admin fallback |

Communication Intake primary/fallback is **not** used for Sales Lead cases.

### Audit fields (`service_case.assigned`)

| Field | Examples |
|---|---|
| `assignment_strategy` | `sales_queue_round_robin` |
| `fallback_used` | `true` / `false` |
| `reason` | `sales_round_robin` / `sales_rr_unavailable` / `ira_memory_override` |
| `decision_source` | `sales_rr` / `sales_fallback` / `ira_memory` |
| `override_reason` | `sales_fallback` (when RR unavailable) |

`incoming_email.routed.assignment_source` mirrors: `sales_round_robin` / `sales_fallback` / `ira_memory`.

### Implementation

- `app/Services/IncomingEmail/IncomingEmailSalesAssignmentService.php` — resilient Sales Lead assigner
- `IncomingEmailAssignmentService::routeLinkedEmail` — Sales Lead → sales strategy (not Communication Intake)
- `IncomingEmailSmartRoutingAssignmentService::assignSalesRoundRobin` — delegates with IRA override allowed when Smart Routing is on
- Tests: `tests/Feature/IncomingEmail/IncomingEmailSalesLeadAssignmentTest.php`

---

## Ownership preservation fix

**Date applied:** 2026-08-06  
**Defect addressed:** 42 Support → Ready ownership steals in this window (`reassignToShiftAdminAfterValidation` after identity validation).

### Rule

If a case has a **current owner** and `assignment_origin` is one of:

| Origin | Value |
|---|---|
| Support | `support` |
| Appointment | `appointment_smart_assignment` |
| Refund | `refund` |
| Sales | `sales` |
| Manual | `manual` |

then Ready Queue **must not overwrite ownership**.

Ready Queue may still update priority, SLA posture, and queue membership. Never the owner.

### Exceptions (ownership may change)

- Supervisor / manual override (`ServiceCaseAssignmentService::reassign`, `override_reason=manual_reassign`)
- Explicit transfer / escalate (manual assignment paths)

### Audit

When Ready validation would have stolen ownership, emit:

`ready_queue_owner_preserved`

with `preserved_origin` and reason `ready_queue_must_not_overwrite_human_ownership`.

### Implementation notes

- Support / Refund / Sales assign paths now persist distinct origins (previously collapsed into `auto`).
- `AssignmentOrigin::Auto` agent ownership may still move to shift admin on Ready validation (legacy auto path).
- Tests: `tests/Feature/ReadyQueueOwnerPreservationTest.php` (Support / Appointment / Refund / Manual preserve + supervisor override).

---

## Method

- Production query: `Incident.created_at >= 2026-08-05 18:00 Asia/Kolkata`.
- Assignment path inferred from `audit_logs` events `service_case.assigned|reassigned|unassigned`.
- Ready Queue membership via `OperationsQueueClassifier` on openish statuses at snapshot time.
- Email detail via `incoming_email_messages` linked to the five `source=email` incidents.
- Investigation snapshot was read-only; ownership-preservation and Sales Lead assignment fixes applied afterward in application code.
