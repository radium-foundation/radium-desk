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
];
