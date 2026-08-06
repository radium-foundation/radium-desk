# Attendance Phase 2.1 — Daily Short Attendance Review Workflow

Shift Short Attendance review from a payroll-time activity to a **daily operational workflow**.

Phase 1 calculation and Phase 2 decision semantics are **unchanged**. This phase only improves how and when HR reviews pending SA cases.

Canvas / pairing: this deliverable is markdown-primary (no canvas).

---

## Business goal

HR (Shipra) reviews Short Attendance **every working day**. Payroll validation remains the final safety check (lock / finalize blocked while pending reviews remain).

---

## Daily workflow

```
Attendance Finalization
        ↓
Today's Short Attendance
        ↓
HR Review
        ↓
Decision
        ↓
Attendance Finalized (for that case)
```

Default queue: **Today’s Pending Reviews**, sorted **Oldest Pending First**.

---

## Review queue

| Filter | Behavior |
|--------|----------|
| Today | Work date = today (default) |
| Yesterday | Work date = yesterday |
| Last 7 Days | Inclusive rolling window |
| This Month | Calendar month (`month=YYYY-MM` supported for payroll deep-links) |
| Pending | `status = pending_review` (default) |
| Decided | Reviewed cases |
| All | Both |

Case page: **Previous / Next** plus keyboard `←` / `→` (also `K` / `J`). After Save Decision, HR advances to the next pending case in the same filter (or back to the queue when none remain).

Morning reminder banner appears while **yesterday still has pending** reviews (queue + Attendance dashboard).

---

## Notification flow

```
Evening (configurable WORKFORCE_SA_EVENING_REVIEW_TIME, default 18:45)
  → sync today’s SA pending
  → if pending_today == 0 → no notification
  → else notify designated reviewer (WORKFORCE_SA_REVIEWER_EMAIL / leave approver / Shipra)
```

Notification content:

- Title: `Today's Short Attendance Review`
- Message: `Pending: N employees`
- Link: Open Review Queue (`period=today&status=pending`)

Command: `workforce:send-short-attendance-evening-review`  
Schedule: `bootstrap/app.php` dailyAt configured time.

---

## Dashboard changes

### Attendance Dashboard

Widget **Today's SA** shows today’s pending count. Click opens today’s pending queue.

### Mission Control

Card **Today's Short Attendance Review**:

| Metric | Source |
|--------|--------|
| Pending Today | Today’s pending SA reviews |
| Pending Yesterday | Yesterday’s pending |
| Total Pending | All pending |

Health color:

| Total pending | Color |
|---------------|-------|
| 0 | Green (Healthy) |
| 1–5 | Yellow (Warning) |
| >5 | Red (Critical) |

Card remains visible at zero pending so HR sees a green all-clear.

---

## Payroll validation

| Action | Pending SA for month |
|--------|----------------------|
| Payroll generation / live draft | Allowed |
| Attendance month **lock** | Blocked |
| Payroll **finalize** | Blocked |

Block message example:

> Cannot finalize payroll. 3 Short Attendance reviews are still pending. Open Review Queue: …

Payroll index also shows a warning with **Open Review Queue** when the selected month has pending SA.

---

## Future ready (not implemented)

Evidence panel reserved for later:

- Manager comments
- Employee explanation
- WFH request
- Field work
- Evidence upload

Do not implement in 2.1.

---

## Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `WORKFORCE_SA_EVENING_REVIEW_TIME` | `18:45` | Evening notify schedule |
| `WORKFORCE_SA_REVIEWER_EMAIL` | leave approver / `shipra@radiumbox.com` | Designated HR recipient |

Phase 1 threshold (`ATTENDANCE_SHORT_ATTENDANCE_MINUTES`) unchanged.

---

## Files changed / added

### New
- `app/Notifications/ShortAttendanceEveningReviewNotification.php`
- `app/Console/Commands/SendShortAttendanceEveningReviewCommand.php`
- `tests/Feature/Workforce/ShortAttendanceDailyReviewPhase21Test.php`
- `docs/attendance-daily-short-attendance-review.md`

### Updated
- `app/Services/Workforce/ShortAttendance/ShortAttendanceReviewQueryService.php` — periods, oldest-pending sort, adjacent / next
- `app/Services/Workforce/ShortAttendance/ShortAttendanceReviewService.php` — daily counts, evening notify, payroll assert
- `app/Http/Controllers/Workforce/ShortAttendanceReviewController.php` — filters, next-after-decide
- `app/Http/Controllers/Workforce/MonthlyAttendanceController.php` — Today’s SA widget + morning reminder
- `app/Http/Controllers/Workforce/PayrollController.php` — pending SA warning on index
- `app/Services/Workforce/PayrollMonthLockService.php` — block lock when pending
- `app/Services/Workforce/Payroll/PayrollRunService.php` — block finalize when pending
- `app/Services/Platform/Cards/PendingShortAttendanceReviewsCardProvider.php` — today/yesterday/total + colors
- `resources/views/workforce-management/short-attendance/*`
- `resources/views/workforce-management/attendance/index.blade.php`
- `resources/views/workforce-management/payroll/index.blade.php`
- `resources/views/admin/platform/cards/pending-short-attendance-reviews.blade.php`
- `resources/views/workforce-management/partials/workspace-nav.blade.php`
- `config/workforce.php`, `.env.example`, `bootstrap/app.php`
- `tests/Feature/Workforce/ShortAttendanceReviewPhase2Test.php` — period defaults / redirect after decide

---

## Test results

| Check | Test |
|-------|------|
| Today’s widget count | `test_today_widget_count_on_attendance_dashboard` |
| Evening notification | `test_evening_notification_sent_when_pending_today` |
| No notify when zero | `test_evening_notification_skipped_when_zero_pending` |
| Morning reminder | `test_morning_reminder_when_yesterday_still_pending` |
| Pending filters / sort | `test_pending_filters_default_today_oldest_first` |
| Next after decide | `test_decide_advances_to_next_pending` |
| Payroll lock blocked | `test_payroll_lock_blocked_when_pending_reviews_exist` |
| Payroll lock allowed when clear | `test_payroll_lock_allowed_when_no_pending_reviews` |
| Payroll finalize blocked | `test_payroll_finalize_blocked_when_pending_reviews_exist` |
| No impact on Phase 1 / Phase 2 decisions | `test_phase1_register_and_phase2_decision_semantics_unchanged` + existing Phase 2 suite |

```bash
php artisan test tests/Feature/Workforce/ShortAttendanceReviewPhase2Test.php tests/Feature/Workforce/ShortAttendanceDailyReviewPhase21Test.php
```

**Result (2026-08-06):** 17 tests, 0 failures — Phase 2 suite unchanged in decision/payroll semantics; Phase 2.1 daily workflow checks green.
