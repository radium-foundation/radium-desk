<?php

/**
 * Hostinger Cloud scheduler hardening.
 *
 * schedule:run is flock-locked (~1 minute budget). Long work must runInBackground
 * with short withoutOverlapping TTLs so the parent cron can exit quickly.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Per-invocation batch caps (dispatcher commands)
    |--------------------------------------------------------------------------
    */

    'outbox_process_limit' => max(1, (int) env('SCHEDULER_OUTBOX_PROCESS_LIMIT', 50)),

    'automation_pending_limit' => max(1, (int) env('SCHEDULER_AUTOMATION_PENDING_LIMIT', 25)),

    /*
    |--------------------------------------------------------------------------
    | withoutOverlapping expiry (minutes)
    |--------------------------------------------------------------------------
    |
    | Every-minute jobs must not use Laravel's 24h default. Keep slightly above
    | expected runtime so a hard-killed process cannot pin the mutex all day.
    |
    */

    'overlap_minutes' => [
        'every_minute' => max(1, (int) env('SCHEDULER_OVERLAP_EVERY_MINUTE', 2)),
        'every_five_minutes' => max(1, (int) env('SCHEDULER_OVERLAP_EVERY_FIVE_MINUTES', 5)),
        'every_fifteen_minutes' => max(1, (int) env('SCHEDULER_OVERLAP_EVERY_FIFTEEN_MINUTES', 15)),
        'hourly' => max(1, (int) env('SCHEDULER_OVERLAP_HOURLY', 55)),
        'daily' => max(1, (int) env('SCHEDULER_OVERLAP_DAILY', 120)),
    ],

    /*
    |--------------------------------------------------------------------------
    | CLI set_time_limit ceilings (seconds)
    |--------------------------------------------------------------------------
    |
    | Keep below the matching withoutOverlapping window. 0 disables the cap.
    |
    */

    'timeouts' => [
        'outbox_process_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_OUTBOX_PROCESS', 120)),
        'cashfree_auto_recover_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_CASHFREE_AUTO_RECOVER', 240)),
        'missing_serial_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_MISSING_SERIAL', 240)),
        'team_daily_briefings_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_TEAM_DAILY_BRIEFINGS', 300)),
        'team_slot_reminders_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_TEAM_SLOT_REMINDERS', 180)),
        'team_appointment_reminders_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_TEAM_APPOINTMENT_REMINDERS', 120)),
        'automation_snapshot_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_AUTOMATION_SNAPSHOT', 120)),
        'automation_run_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_AUTOMATION_RUN', 300)),
        'ira_send_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_IRA_SEND', 180)),
        'metrics_collect_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_METRICS_COLLECT', 120)),
        'executive_snapshot_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_EXECUTIVE_SNAPSHOT', 180)),
        'attendance_reconcile_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_ATTENDANCE_RECONCILE', 300)),
        'watchdog_seconds' => max(0, (int) env('SCHEDULER_TIMEOUT_WATCHDOG', 60)),
    ],

];
