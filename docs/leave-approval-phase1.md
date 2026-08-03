# Leave Approval Phase 1 — Centralized Approver + Action-First UX

**Type:** Implementation + investigation  
**Scope:** Phase 1 only (no coverage planning / auto-reassignment / leave automation / calendar)  
**Canvas:** [leave-approval-phase1.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/leave-approval-phase1.canvas.tsx)  
**Snapshot / production bug:** Avinash leave #15 pending; Shipra blocked by role hierarchy (confirmed 2026-08-03)

---

## 1. Root Cause

**Bug:** Shipra could approve Shubhanshi (#16) and Sushant (#17), but could not approve Avinash (#15).

**Exact path:** `LeaveRequestPolicy::review` → `LeaveRequestService::canReview`

**Old hierarchy rule:**

| Requester role | Required reviewer |
|---|---|
| `agent` / `escalation_specialist` / other employee roles | `operations_admin` |
| `admin` / `operations_admin` | **`superadmin` only** |

Avinash is `admin`. Shipra is `admin` + `operations_admin`, **not** `superadmin`.

```
canReview(Shipra, Avinash leave #15)
  → leave-requests.review ✓
  → requester has role admin ✓
  → return Shipra->hasRole(superadmin) → FALSE
```

Employee leaves (Shubhanshi, Sushant) matched the ops-admin branch, so Shipra could approve those.

Notifications used the same hierarchy via `eligibleApprovers()`, so Shipra was never treated as approver for admin leave.

---

## 2. Business Rule (Phase 1)

Leave approval is centralized.

- Exactly **one** leave approver: **Shipra** (permanent Leave Authority)
- Config: `workforce.leave_approver.email` / `WORKFORCE_LEAVE_APPROVER_EMAIL` (default `shipra@radiumbox.com`)
- Do **not** use reporting manager, shift admin, operations-admin fallback, logged-in admin pool, or role hierarchy
- Shipra may approve employee leave, admin leave, **and her own leave**
- Self-approval remains blocked for every other user
- Self-approval audit records `approved_by`, `actor`, and `self_approved = true`
- Audit logs, approval history, Telegram notifications, and payroll integration remain unchanged (submit notify still skips self)

---

## 3. Files Changed

| File | Change |
|---|---|
| `config/workforce.php` | Added `leave_approver.email` |
| `app/Services/Operations/LeaveRequestService.php` | Centralized `canReview` / `eligibleApprovers`; pending helpers |
| `app/Http/Controllers/LeaveRequestController.php` | Pending Today/Upcoming data; `return_to=index` redirect |
| `resources/views/leave-requests/index.blade.php` | Action-first pending sections + inline Approve/Reject |
| `resources/views/leave-requests/partials/pending-approval-row.blade.php` | Inline review row |
| `app/Services/Operations/Workforce360Service.php` | `pendingLeaveApprovalsCard()` |
| `app/Http/Controllers/Workforce360Controller.php` | Pass card payload |
| `resources/views/workforce/team.blade.php` | Include pending card |
| `resources/views/workforce/partials/pending-leave-approvals.blade.php` | Workforce card UI |
| `app/Services/Platform/Cards/PendingLeaveApprovalsCardProvider.php` | Mission Control card |
| `resources/views/admin/platform/cards/pending-leave-approvals.blade.php` | MC card body |
| `app/Providers/PlatformDashboardServiceProvider.php` | Register MC card |
| `tests/Feature/Workforce/CentralizedLeaveApprovalPhase1Test.php` | New Phase 1 coverage |
| Existing leave hierarchy tests | Updated for designated-approver rule |

---

## 4. Before / After Flow

### Before

```
Submit leave
  → eligibleApprovers by requester role
      employee → all operations_admins
      admin/ops → all superadmins
  → canReview uses same hierarchy
  → Shipra blocked on admin leave (Avinash)
  → UX: Mission Control → Leave → Filter Pending → View → Approve
```

### After

```
Submit leave
  → eligibleApprovers = [Shipra] only (email match + active + review permission + not self)
  → canReview = designated Shipra only (any requester role)
  → Avinash leave reviewable by Shipra
  → UX:
      Workforce / Mission Control → Pending Leave Approvals card → Review
      Leave page → Today / Upcoming → Approve / Reject inline
```

---

## 5. Dashboard + Leave Page UX

### Pending Leave Approvals card

- Shown on Workforce 360 and Mission Control Workforce section
- Visible only when pending requests exist **and** viewer is designated approver
- Shows employee, dates label (Today / range), submitted age, Review CTA
- Review opens the leave show page (direct approval)

### Leave Requests index

- Pending Leave Approvals block with **Today** and **Upcoming**
- Columns/fields: Employee, dates, duration, submitted age, reason preview
- Inline **Approve** / **Reject** with required note (no extra navigation)
- Existing filter table retained for history / non-pending browsing

---

## 6. Regression Analysis

| Area | Impact |
|---|---|
| Employee leave → Shipra | Still works (email match) |
| Admin leave → Shipra | **Fixed** (was superadmin-only) |
| Other ops admins approving | **Blocked** (by design) |
| Superadmin approving | **Blocked** (by design; was required for admin leave) |
| Self-approval (Shipra) | **Allowed** — Leave Authority exception |
| Self-approval (everyone else) | Still blocked |
| Telegram submit notify | Only Shipra (skipped for her own submission) |
| Audit / payroll / attendance refresh | Unchanged on approve/reject |
| Coverage / auto-reassign | Not implemented (Phase 2) |

---

## 7. Tests

New: `tests/Feature/Workforce/CentralizedLeaveApprovalPhase1Test.php`

- Shipra self-approves (audit: `approved_by`, `actor`, `self_approved=true`)
- Other users cannot self-approve
- Shipra still approves everyone else (`self_approved=false`)
- Non-designated users still cannot approve any leave
- Notifications only to designated approver
- Today / Upcoming grouping
- Index inline approve + redirect
- Workforce card visibility
- Mission Control card authorize when pending

Updated hierarchy tests in:

- `LeaveRequestServiceTest`
- `WorkforceGovernancePhase1Test`
- `WorkforceCalendarTest`
- Stabilization / attendance / event / telegram fixtures using Shipra email

**Result:** 55 related tests passed.

---

## 8. Deployment Safety

1. **Config only** — default email is already `shipra@radiumbox.com`; no DB migration.
2. **Optional env:** `WORKFORCE_LEAVE_APPROVER_EMAIL=shipra@radiumbox.com` (explicit is safer).
3. **Immediate effect after deploy:** Shipra can approve Avinash #15 without code data fix.
4. **No change** to existing approved/rejected history rows.
5. **Verify after deploy:**
   - Shipra opens Workforce → sees Pending Leave Approvals (Avinash)
   - Shipra can Approve Avinash from Leave index or Review deep link
   - Non-Shipra ops/admin cannot Approve
6. **Rollback:** revert commit; hierarchy returns (admin leave stuck again for Shipra).
7. **Known Phase 1 limit:** none for self-approval — Shipra may approve her own leave with `self_approved=true` audit metadata.

---

## Out of scope (Phase 2)

- Coverage planning
- Auto reassignment of owned cases
- Leave automation beyond approval routing
- Calendar improvements
