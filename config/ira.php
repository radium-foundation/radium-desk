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
    ],
];
