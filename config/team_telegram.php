<?php

return [
    'enabled' => (bool) env('TEAM_TELEGRAM_ENABLED', true),

    'daily_briefing' => [
        'minutes_before_work_start' => (int) env('TEAM_TELEGRAM_BRIEFING_MINUTES_BEFORE', 60),
    ],

    'support_slots' => [
        'morning' => '09:00',
        'afternoon' => '12:00',
        'evening' => '15:00',
    ],

    'appointment_reminders' => [
        'enabled' => (bool) env('TEAM_TELEGRAM_APPOINTMENT_REMINDERS_ENABLED', true),
        'schedule_interval_minutes' => max(1, (int) env('TEAM_TELEGRAM_APPOINTMENT_REMINDERS_INTERVAL', 1)),
        'role_thresholds_minutes' => [
            'default' => [30, 10, 0],
            'support_specialist' => [30, 10, 0],
            'agent' => [30, 10, 0],
            'admin' => [30, 10, 0],
            'escalation_specialist' => [30, 10, 0],
        ],
    ],
];
