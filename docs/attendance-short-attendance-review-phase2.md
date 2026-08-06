# Attendance Phase 2 — End-of-Day Short Attendance Review

HR review layer for Phase 1 Short Attendance cases before payroll.

**Phase 1 calculation is unchanged.** Register status remains `short_attendance` until/unless HR decides; matrix and payroll consume the **final approved status** from the review layer.

Canvas / pairing: this deliverable is markdown-primary (no canvas).

---

## Business rules

| Rule | Behavior |
|------|----------|
| Source of truth | Phase 1 register status (`short_attendance`) |
| Until HR reviews | Cell stays **SA · Short Attendance** |
| No automatic conversion | Pending cases never auto-approve |
| If no review | SA remains SA for payroll (Absent / non-payable) |
| Who can decide | HR + Operations Admin only (`workforce.short_attendance.review` + attendance access) |
| Employees / managers | Cannot view or decide |

### HR actions

| Action | Final matrix/payroll kind | Payable |
|--------|---------------------------|---------|
| Approve Full Day | Present | 1.0 |
| Approve Half Day | Half Day | 0.5 |
| Keep Short Attendance | Short Attendance | 0.0 (Absent) |
| Mark Leave | Leave | Leave rules (default paid if no leave row) |

Every decision requires a **reason**. Optional **note** allowed.

---

## Decision / data flow

```
Phase 1 AttendanceDayCalculator
  → workforce_attendance_days.status = short_attendance
  → ShortAttendanceReviewService::ensurePendingForDay()
       creates pending workforce_short_attendance_reviews row

HR decides
  → audit_logs + workforce event
  → review.status = decided + decision/new_status

MonthlyAttendanceMatrixService / Payroll
  → AttendanceMatrixCellMapper::kindFor(..., override)
  → uses HR final kind when decided; else SA
```

Register row is **never** rewritten to Present/Half Day/Leave by Phase 2.

---

## Information shown (review queue / case)

- Employee
- Worked minutes
- First login
- Last activity
- Auto logout (yes/no + away timeout count)
- Sessions
- Shift
- Department
- Manager (placeholder — no reporting-manager field in User yet)
- Reason (`short_attendance`)

---

## Audit (every override)

Stored on decide:

| Field | Source |
|-------|--------|
| HR User | `decided_by` + audit `user_id` |
| Timestamp | `decided_at` |
| Previous Status | `previous_status` (`short_attendance`) |
| New Status | `new_status` (`present` / `half_day` / `short_attendance` / `leave`) |
| Reason | `decision_reason` (required) |
| Optional Note | `decision_note` |

Events:

- `workforce.attendance.short_review.created`
- `workforce.attendance.short_review.decided`

---

## Permissions

| Permission | Roles |
|------------|-------|
| `workforce.short_attendance.view` | Admin, Operations Admin, Super Admin |
| `workforce.short_attendance.review` | Admin, Operations Admin, Super Admin |

Plus `AttendanceManagementAccess::allows()` (team-performance.view + optional email allowlist). Agents/managers lack these permissions.

---

## UI entry points

1. **Workforce Management → Short Attendance Review** tab  
   Route: `workforce-management.short-attendance.index`
2. **Attendance page widget** — “SA Pending Review” count → opens queue
3. **Mission Control / Platform card** — Short Attendance Review

---

## Files changed / added

### New
- `app/Enums/ShortAttendanceReviewStatus.php`
- `app/Enums/ShortAttendanceReviewDecision.php`
- `app/Models/WorkforceShortAttendanceReview.php`
- `database/migrations/2026_08_06_110000_create_workforce_short_attendance_reviews_table.php`
- `app/Services/Workforce/ShortAttendance/ShortAttendanceReviewService.php`
- `app/Services/Workforce/ShortAttendance/ShortAttendanceReviewQueryService.php`
- `app/Http/Controllers/Workforce/ShortAttendanceReviewController.php`
- `app/Http/Requests/DecideShortAttendanceReviewRequest.php`
- `app/Services/Platform/Cards/PendingShortAttendanceReviewsCardProvider.php`
- `resources/views/workforce-management/short-attendance/index.blade.php`
- `resources/views/workforce-management/short-attendance/show.blade.php`
- `resources/views/admin/platform/cards/pending-short-attendance-reviews.blade.php`
- `tests/Feature/Workforce/ShortAttendanceReviewPhase2Test.php`
- `docs/attendance-short-attendance-review-phase2.md`

### Touched
- `AttendanceRegisterService` — sync pending review after persist (no status mutation)
- `AttendanceMatrixCellMapper` — optional SA override kind
- `MonthlyAttendanceMatrixService` — preload decided overrides for payroll/matrix
- `MonthlyAttendanceController` + attendance index — HR widget
- `workspace-nav.blade.php` — review tab
- `routes/web.php`
- `RolePermissionSeeder`
- `WorkforceAuditEvent` / `WorkforceEventType`
- `PlatformDashboardServiceProvider`

---

## Configuration

No new threshold. Phase 1 `ATTENDANCE_SHORT_ATTENDANCE_MINUTES` remains the calculation config.

Attendance page access still uses `WORKFORCE_ATTENDANCE_MANAGEMENT_*` allowlist when restricted (Shipra + Ops).

---

## Test plan / results

| Check | Coverage |
|-------|----------|
| SA appears in review queue | `test_short_attendance_appears_in_review_queue` |
| Approve Full Day | `test_hr_can_approve_full_day_and_payroll_uses_present` |
| Approve Half Day | `test_hr_can_approve_half_day` |
| Keep SA | `test_hr_can_keep_short_attendance` |
| Payroll reflects final decision | Full Day / Half Day / Keep SA cases |
| Unreviewed SA stays SA | `test_unreviewed_short_attendance_remains_short_attendance_for_payroll` |
| Audit records override | Full Day case asserts audit log fields |
| Unauthorized cannot approve | `test_unauthorized_users_cannot_approve` |
| Phase 1 register unchanged | `test_phase1_register_status_unchanged_after_override` |

```bash
php artisan migrate
php artisan test --filter=ShortAttendanceReviewPhase2Test
```

---

## Future enhancements (out of scope)

- Manager acknowledgment / escalation
- Sync “Mark Leave” into `leave_requests` table
- Reporting-manager field on User
- Bulk decide
- Grace / WFH exception workflows
- Re-open decided reviews under payroll lock policy
