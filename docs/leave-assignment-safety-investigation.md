# Leave & Assignment Safety Investigation

**Type:** Production investigation only (no code changes)  
**Snapshot:** 2026-08-03 14:02 IST  
**Source:** Production DB via read-only probe  
**Canvas:** [leave-assignment-safety.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/leave-assignment-safety.canvas.tsx)

---

## Bottom line

- **Demo Agent** is currently safe only because the account is **inactive**. By role it remains in the normal assignment pool, and its email does **not** match the manual-assign demo exclusion (`demo@*` / `demo@radiumbox.com`).
- **Shubhanshi’s leave for today is Pending (not Approved)**, so the leave engine has **not** excluded her.
- As **L1 escalation target** she can still receive escalations even after leave approval (escalation path does not check leave).

---

## 1. Demo Agent Safety

| Field | Value |
|---|---|
| User ID | 13 |
| Name | Demo Agent |
| Email | `demo.agent@radiumbox.com` |
| Role | `agent` |
| Active | **false** |
| Availability | Offline |
| Present | No (no open work session) |
| In normal assignment pool | **Yes** |
| Excluded from manual assign | **No** (email is not `demo@…`) |
| Eligible for normal assignment now | **No** (inactive + offline + not present) |

### Can this user receive work?

| Work type | Now | If activated + Available + present | Why |
|---|---|---|---|
| New Cashfree orders | No | **Yes** — round-robin / grace expiry | `agent` is in SUPPORT_TEAM pool |
| Ready Queue cases | No | No (as agent) | Ready assigns to shift admin, not support pool |
| Scheduled appointments | No | **Yes** — Smart Assignment | Same eligibility stack |
| Support callbacks | No | **Yes** — via appointments | Callbacks ride appointment / smart assign |
| Automation work | No | **Yes** — grace → RR / Ready | Support-pool branch uses eligibility |
| Refund work | No dedicated path | Only if manually assigned | No refund-specific auto assignee |
| Escalations | No | No | L1 target is `shubhanshi@radiumbox.com` |

### Assignment path gates

| Path | Would include demo now? | Evidence |
|---|---|---|
| Smart Assignment | No (inactive) | Requires active + pool + on duty |
| Ready Queue | No | Shift admin emails only |
| Appointment assign | No (inactive) | Smart Assignment eligibility |
| Shift assignment | No | Day/after-hours admins ≠ demo |
| Automation assign | No (inactive) | `activeSupportAgents` filters `is_active` |
| Deferred smart assign | No (inactive) | Same eligibility resolver |
| Manual assign dropdown | Not email-excluded | Exclusion is `demo@*` or config list |
| Queue membership | Role=`agent` → support pool | `in_normal_assignment_pool=true` |
| Active shift | No open WorkSession | `not_present` + offline |
| Escalation L1 | No | Config L1 = `shubhanshi@` |

### Demo configuration gap

Manual exclusion only matches emails starting with `demo@` or listed in `SERVICE_CASE_MANUAL_ASSIGN_EXCLUDED_EMAILS` (currently `demo@radiumbox.com`).

Production demo user is `demo.agent@radiumbox.com` — **not excluded**. There is **no** `is_demo` DB flag.

### Safest production config for a login-only demo/test user

Do all of the following:

| Control | Setting | Why |
|---|---|---|
| Account | Keep `is_active = false` when not testing UI | Hard-blocks every auto pool |
| Email | Use `demo@…` or add exact email to excluded list | Blocks manual dropdown |
| Role | Non-pool role (e.g. `employee`) — not `agent` | Removes Smart/RR eligibility |
| Presence | Never start a work session / stay Offline | On-duty gate fails |
| Config emails | Never set as day/night/escalation/hardware assignee | Those paths skip leave/presence |
| Capabilities | No Ready Queue / email / WhatsApp capabilities | Capability fallbacks |

**Minimum safe combo:** inactive **or** (non-pool role + Offline + `demo@` exclusion + not in any assignee config). Prefer inactive + non-pool role.

---

## 2. Shubhanshi Leave Status

| Field | Value |
|---|---|
| User ID | 6 |
| Name | Shubhanshi Rathore |
| Email | `shubhanshi@radiumbox.com` |
| Role | `escalation_specialist` |
| Active | true |
| Availability | Offline |
| Escalation L1 target | **Yes** |

### Current leave (covers today)

| Field | Value |
|---|---|
| Request ID | **#16** |
| Type | Full day · Paid |
| Dates | 2026-08-03 → 2026-08-03 |
| Reason | personal reason |
| Status | **Pending** |
| Stage | Awaiting Ops Admin approval |
| Approver | **Shipra** (`operations_admin`) — only eligible reviewer |
| Reviewed | Not yet |

### Status model note

System statuses are only: **Pending**, **Approved**, **Rejected**.  
There is **no Draft** or **Cancelled** leave status in `LeaveRequestStatus`.

### Leave engine impact right now

- `on_approved_leave = false`
- Pending leave does **not** exclude anyone
- Only **Approved** leave covering the day sets `hasApprovedLeave` / forces Offline for normal assignment
- Current block reasons: `not_present`, `availability_offline`, `not_assignment_pool`

### Historical leave (July payroll reconciliation)

| ID | Dates | Duration | Status | Approver | Notes |
|---|---|---|---|---|---|
| 38 | 2026-07-06 | Half day | Approved | Shipra | Payroll reconcile |
| 34 | 2026-07-07 | Full day | Approved | Shipra | Payroll reconcile |
| 35 | 2026-07-16 | Full day | Approved | Shipra | Payroll reconcile |
| 36 | 2026-07-17 | Full day | Approved | Shipra | Payroll reconcile |
| 37 | 2026-07-23 | Full day | Approved | Shipra | Payroll reconcile |
| 16 | 2026-08-03 | Full day | **Pending** | — | personal reason |

### Audit timeline — leave #16

| When (IST) | Event | Actor | Detail |
|---|---|---|---|
| 2026-07-29 15:53:29 | `workforce.leave.submitted` | Shubhanshi (#6) | status=pending · 2026-08-03 |
| 2026-07-29 15:53:30 | `workforce.leave.notification.dispatched` | system | Telegram → Shipra (#3) · Leave Request Submitted |

No approve/reject audit yet for #16. July leaves #34–#38 each have submitted → telegram → approved → telegram sequences (Shipra).

---

## 3. Current Assignment Summary — Shubhanshi

| Metric | Count |
|---|---|
| Open service cases | **14** |
| Scheduled appointments | **2** (stale Jul 8 & Jul 10) |
| Ready-queue visible | **6** |
| Waiting Customer | **0** |
| Refund keyword cases | **0** |
| Assignment capabilities | **0** |
| Open work sessions | **0** |

### Operational responsibility checklist

| Item | Count | Verdict |
|---|---|---|
| Open service cases | 14 | Yes — still owned |
| Scheduled appointments | 2 (stale) | Yes — status still scheduled |
| Ready Queue visibility | 6 | Overlay on owned cases |
| Waiting Customer | 0 | None |
| Refund cases | 0 | None found |
| Active callbacks | 2 appointment-linked | Via scheduled appointments |
| Automation ownership | 6 origin=auto | Retained ownership |
| Escalation L1 target | Config live | Yes — will receive new escalations |

### Queue / status breakdown

**Ops queue:** action_required 8 · attention 4 · completed (classifier) 2 · waiting_customer 0  

**Incident status:** open 10 · awaiting_product_details 4  

**Origins:** 8 manual · 6 auto

### Owned open cases

| Case | ID | Status | Queue | Origin | Ready visible | Appointment |
|---|---|---|---|---|---|---|
| SC21791 | 21873 | awaiting_product_details | action_required | manual | No | — |
| SC19643 | 19727 | awaiting_product_details | action_required | manual | No | — |
| SC17719 | 17808 | open | action_required | manual | No | — |
| SC17421 | 17511 | open | action_required | manual | No | — |
| SC16914 | 17006 | open | action_required | manual | No | — |
| SC15507 | 15600 | open | action_required | manual | No | — |
| SC14273 | 14367 | open | action_required | manual | No | — |
| SC11188 | 11289 | open | attention | auto | Yes | — |
| SC09236 | 9343 | open | attention | auto | Yes | — |
| SC08984 | 9091 | open | action_required | manual | No | — |
| SC08682 | 8789 | open | completed* | auto | Yes | — |
| SC08582 | 8689 | open | completed* | auto | Yes | — |
| SC07604 | 7711 | awaiting_product_details | attention | auto | Yes | 2026-07-10 morning (#93) |
| SC07419 | 7526 | awaiting_product_details | attention | auto | Yes | 2026-07-08 evening (#59) |

\* `completed` = OperationsQueueClassifier result while incident status remains open.

---

## 4. Leave Engine vs Assignment

**Has the engine already excluded Shubhanshi?**  
No — for leave. Leave #16 is Pending. Exclusion requires Approved leave covering today.

Separately, `escalation_specialist` is outside the normal auto pool (`not_assignment_pool`), so she does not get Cashfree round-robin / Smart Assignment / deferred auto work. Escalations are a different path.

| Path | Excluded by approved leave? | Would still assign to Shubhanshi? |
|---|---|---|
| Smart Assignment / RR / deferred | Yes | No — also role-excluded from pool |
| Ready Queue (shift admin) | No leave check | No — not day/after-hours admin |
| Escalation L1 | **No leave check** | **Yes** — if active + role + L1 email match |
| Manual reassign dropdown | No leave check | Yes — can be manually assigned while on leave |
| Hardware fixed-email routing | No leave check | Only if configured as hardware email |

### If leave #16 is approved today

`on_approved_leave` becomes true → effective availability Offline for normal eligibility. She remains out of the support pool (already). Escalation L1 `resolveLevel1Target()` only checks active + `escalation_specialist` + config email — **it does not check leave**. Manual assignment still allowed. Existing 14 owned cases stay assigned until reassigned.

---

## 5. Risks Before Testing Leave Module

| Risk | Severity | Detail |
|---|---|---|
| Approve Shubhanshi leave → escalations still land on her | High | L1 email path ignores leave/presence |
| 14 open cases remain owned during leave | High | Leave approval does not reassign existing work |
| 2 stale scheduled appointments still active | Medium | Jul 8 / Jul 10 still `scheduled` |
| Demo Agent activation would enable auto work | High | agent pool + email not `demo@`-excluded |
| Avinash also has Pending leave for today (#15) | Medium | Day-shift Ready admin path has no leave gate either |
| Pending leave looks like coverage but isn’t | Medium | Operators may assume Pending blocks assignment — it does not |

---

## 6. Recommendation

### Before leave testing

1. Do not activate Demo Agent for production leave tests.
2. If a login demo is required: keep inactive, or switch to non-pool role + `demo@` email + Offline.
3. Before approving Shubhanshi leave: reassign or cover the 14 open cases and decide L1 escalation backup.
4. Temporarily point `SERVICE_CASE_ESCALATION_LEVEL_1_EMAIL` at a covering specialist, or pause escalations operationally.
5. Treat Pending leave as non-blocking in all test expectations.

### Safest leave-test sequence

1. Cover/reassign Shubhanshi’s open work.
2. Change or staff L1 escalation target for the leave day.
3. Approve leave #16 (Ops Admin: Shipra).
4. Verify `on_approved_leave` / Offline on workforce views.
5. Confirm support-pool paths still skip her (already role-excluded).
6. Explicitly test that escalation still routes unless L1 email is changed — document as known gap.

---

## Production config at snapshot

| Setting | Value |
|---|---|
| Manual assign excluded emails | `demo@radiumbox.com` |
| Round robin | enabled |
| Escalation L1 | `shubhanshi@radiumbox.com` |
| Escalation L2 | `shipra@radiumbox.com` |
| Day admin | `avinash@radiumbox.com` |
| After hours | `shipra@radiumbox.com` |
| Active support-pool agent | Vanshika Baniwal only |
