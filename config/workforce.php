<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Centralized Leave Approver (Phase 1)
    |--------------------------------------------------------------------------
    |
    | Exactly one designated leave approver (Leave Authority). Approval does not
    | use reporting manager, shift admin, role hierarchy, or "any operations admin"
    | fallback. The Leave Authority may approve every leave request, including
    | their own; self-approval remains blocked for every other user.
    |
    */
    'leave_approver' => [
        'email' => strtolower(trim((string) env(
            'WORKFORCE_LEAVE_APPROVER_EMAIL',
            'shipra@radiumbox.com',
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra Day Qualification (Milestone 5)
    |--------------------------------------------------------------------------
    |
    | When disabled (default), ExtraQualificationEngine mirrors today's Extra
    | attendance status and publishes no events. Attendance is never mutated.
    |
    */
    'extra_qualification' => [
        'enabled' => (bool) env('WORKFORCE_EXTRA_QUALIFICATION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance Management page lock (temporary — payroll reconciliation)
    |--------------------------------------------------------------------------
    |
    | When restricted, /workforce-management/attendance stays behind the usual
    | team-performance.view permission AND an email allowlist (Ravi + Shipra).
    | Salaries / Payroll require Attendance access + workforce.payroll.manage
    | (Super Admin + Operations Admin). Admin remains attendance-only.
    |
    | Revert after payroll: set WORKFORCE_ATTENDANCE_MANAGEMENT_RESTRICTED=false
    | (or remove the AttendanceManagementAccess checks).
    |
    */
    'attendance_management' => [
        'restricted' => (bool) env('WORKFORCE_ATTENDANCE_MANAGEMENT_RESTRICTED', true),
        'allowed_emails' => array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env(
                'WORKFORCE_ATTENDANCE_MANAGEMENT_ALLOWED_EMAILS',
                'info@radiumbox.com,shipra@radiumbox.com',
            )),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Work Recognition (independent of Attendance / OT)
    |--------------------------------------------------------------------------
    |
    | Master switch mirrored from config/workforce_recognition.php for discoverability.
    | Prefer workforce_recognition.enabled in application code.
    |
    */
    'recognition' => [
        'enabled' => (bool) env('WORKFORCE_RECOGNITION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Short Attendance daily review (Phase 2.1)
    |--------------------------------------------------------------------------
    |
    | Evening in-app notification to designated HR when today's SA pending > 0.
    | Reviewer defaults to the centralized leave approver (Shipra) when unset.
    |
    */
    'short_attendance' => [
        'evening_review_time' => (string) env('WORKFORCE_SA_EVENING_REVIEW_TIME', '18:45'),
        // Defaults to Shipra; leave_approver.email is the shared HR mailbox fallback.
        'reviewer_email' => strtolower(trim((string) (
            env('WORKFORCE_SA_REVIEWER_EMAIL')
            ?: env('WORKFORCE_LEAVE_APPROVER_EMAIL', 'shipra@radiumbox.com')
        ))),
    ],

];
