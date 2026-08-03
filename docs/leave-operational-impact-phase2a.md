# Workforce Phase 2A — Leave Operational Impact Analysis

**Type:** Implementation + investigation  
**Scope:** Phase 2A only — read-only impact before leave approval  
**Canvas:** [leave-operational-impact-phase2a.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/leave-operational-impact-phase2a.canvas.tsx)

**Not in scope:** automatic reassignment, assignment-engine changes, leave-routing changes, Phase 2B.

---

## 1. Root Cause

Leave approval was **blind**.

When the Leave Authority (Shipra) reviewed a request, the UI showed only leave fields (dates, reason, notes). It did **not** show whether the employee still owned open cases, appointments, Ready Queue work, escalations, or other operational responsibility.

Approving leave therefore did not surface the operational consequence that **ownership is unchanged** and work is **not** redistributed.

Phase 2A closes that visibility gap without changing assignment behavior.

---

## 2. Business Rule (Phase 2A)

Workflow:

```
Review Leave
  → Operational Impact Analysis (read-only)
  → Approve / Reject
```

Rules:

- Show live operational workload for the employee **before** approval
- **READ ONLY** — do not reassign, modify ownership, or block approval
- If workload exists:  
  `Approving leave will NOT automatically redistribute this work.`
- If no workload:  
  `No operational workload detected.`
- Action buttons: Approve Leave, Reject Leave, plus navigation shortcuts only

---

## 3. Impact Sections

| Section | Source |
|---|---|
| Open service cases | Assigned + `IncidentStatus::operationallyActive()` |
| Awaiting Product Details | Status filter |
| Ready Queue | `isVisibleInAdminReadyQueue` among assigned |
| Waiting Customer | `OperationsQueueClassifier` |
| Scheduled Appointments | Scheduled `SupportAppointment` on owned cases |
| Today's appointments | Scheduled + `preferred_date = today` |
| Callbacks | Scheduled support appointments (callback bookings) |
| Refund work | `RefundRequest` pending / pending_execution by `requested_by` |
| Escalation ownership | L1 email match and/or `escalation_specialist` role → YES/No |
| Automation ownership | Assigned cases with `AssignmentOrigin::Auto` |
| Business Holds | Classifier `BusinessHold` among assigned |
| Active shifts | Open `WorkSession` (+ scheduled-window detail) |
| Current attendance status | Attendance register row + availability (read-only `findDay`) |

Each section shows: **Count**, **Severity**, **View** shortcut.

---

## 4. Files Changed

| File | Change |
|---|---|
| `app/Data/Operations/LeaveOperationalImpact.php` | Read-only DTO |
| `app/Services/Operations/LeaveOperationalImpactService.php` | Impact aggregation (no mutations) |
| `app/Http/Controllers/LeaveRequestController.php` | Pass impact to show for Leave Authority |
| `resources/views/leave-requests/show.blade.php` | Impact + review actions/shortcuts |
| `resources/views/leave-requests/partials/operational-impact.blade.php` | Impact table UI |
| `tests/Feature/Workforce/LeaveOperationalImpactPhase2ATest.php` | Phase 2A coverage |

**Unchanged by design:** assignment engine, appointments engine, smart assignment, escalations routing, attendance writes, payroll, Ready Queue logic, automation assignment, leave approval routing (Phase 1).

---

## 5. Before / After Flow

### Before

```
Leave show
  → leave fields only
  → Approve / Reject
```

### After

```
Leave show (Leave Authority)
  → Operational Impact Analysis (counts + severity + View)
  → warning / clear message
  → Approve Leave / Reject Leave
  → shortcuts: Open Assigned Cases / Appointments / Ready Queue
```

Non–Leave Authority viewers do not see the impact panel or approve actions.

---

## 6. Why Production Safe

1. **Read-only** — service never updates incidents, appointments, sessions, attendance, or leave rows during analysis  
2. Attendance uses `findDay` (no refresh/write path)  
3. Approve/Reject paths unchanged except existing Phase 1 rules  
4. Impact does **not** gate approval — Shipra can still approve with workload present  
5. No schema migration  
6. No assignment-engine / Ready Queue / escalation config changes  

---

## 7. Regression Analysis

| Area | Impact |
|---|---|
| Leave approve / reject | Still works with workload present |
| Leave Authority-only review | Unchanged (Phase 1) |
| Non-designated users | No impact panel, cannot approve |
| Assignment ownership | Unchanged after viewing impact |
| Notifications / payroll / attendance math | Unchanged |
| Auto reassignment | Not implemented (Phase 2B+) |

---

## 8. Tests

File: `tests/Feature/Workforce/LeaveOperationalImpactPhase2ATest.php`

1. Employee with workload → warning + counts + UI  
2. Employee with no workload → clear message  
3. Open cases only  
4. Appointments only (scheduled + today + callbacks)  
5. Ready Queue visible count  
6. Escalation owner → YES  
7. Approved leave still works  
8. Reject still works  
9. No permission regression (non-designated cannot see/approve)  
10. Impact is read-only (assignment unchanged)

**Result:** 10/10 Phase 2A tests passed (24 including Phase 1 authorization suite).

---

## 9. Explicit Non-Goals

- Phase 2B  
- Automatic reassignment  
- Assignment-engine changes  
- Leave-routing changes  
- Workflow redesign beyond impact panel on leave show  
