<?php

return [
    'reasoning_provider' => env('IRA_REASONING_PROVIDER', 'rule_based'),

    'memory' => [
        'retention_days' => 90,
    ],

    'thresholds' => [
        'high_open_cases' => 30,
        'high_scheduled_appointments' => 15,
        'high_waiting_cases' => 50,
        'min_available_staff' => 2,
        'sla_risk_cases' => 3,
        'member_overload_cases' => 8,
        'long_waiting_days' => 7,
        'idle_capacity_minutes' => 15,
    ],

    'communication' => [
        'cooldown_minutes' => (int) env('IRA_NOTIFICATION_COOLDOWN_MINUTES', 60),
        'daily_briefing_time' => env('IRA_DAILY_BRIEFING_TIME', '08:00'),
        'owner_morning_report_time' => env('IRA_OWNER_MORNING_REPORT_TIME', '10:00'),
        'owner_evening_report_time' => env('IRA_OWNER_EVENING_REPORT_TIME', '20:00'),
        'assignment_telegram_batch' => [
            'enabled' => (bool) env('IRA_ASSIGNMENT_TELEGRAM_BATCH_ENABLED', true),
            'delay_minutes' => max(1, (int) env('IRA_ASSIGNMENT_TELEGRAM_BATCH_DELAY_MINUTES', 5)),
        ],
    ],

    'watchdog' => [
        'enabled' => (bool) env('IRA_WATCHDOG_ENABLED', true),
        'schedule_interval_minutes' => max(1, (int) env('IRA_WATCHDOG_INTERVAL_MINUTES', 5)),
        'automation_failure_threshold' => max(1, (int) env('IRA_WATCHDOG_AUTOMATION_FAILURE_THRESHOLD', 3)),
        'interakt_failure_threshold' => max(1, (int) env('IRA_WATCHDOG_INTERAKT_FAILURE_THRESHOLD', 3)),
        'radiumbox_min_success_rate' => (float) env('IRA_WATCHDOG_RADIUMBOX_MIN_SUCCESS_RATE', 80),
    ],
];
