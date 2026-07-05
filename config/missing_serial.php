<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Missing Serial Automation
    |--------------------------------------------------------------------------
    |
    | Automatically contacts customers who paid successfully but still have
    | no device serial after the RadiumBox recovery window.
    |
    */
    'enabled' => env('MISSING_SERIAL_AUTOMATION_ENABLED', true),

    'first_delay_minutes' => (int) env('MISSING_SERIAL_FIRST_DELAY_MINUTES', 45),

    'reminder_delay_hours' => (int) env('MISSING_SERIAL_REMINDER_DELAY_HOURS', 24),

    'escalation_delay_hours' => (int) env('MISSING_SERIAL_ESCALATION_DELAY_HOURS', 72),

    'schedule_interval_minutes' => (int) env('MISSING_SERIAL_SCHEDULE_INTERVAL_MINUTES', 15),

    'batch_limit' => (int) env('MISSING_SERIAL_BATCH_LIMIT', 100),
];
