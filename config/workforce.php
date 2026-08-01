<?php

return [

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
    | Does not affect Member 360 or Team Performance.
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

];
