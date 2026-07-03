<?php

return [
    'enabled' => filter_var(env('RADIUMBOX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'base_url' => rtrim(env('RADIUMBOX_BASE_URL', 'https://admin.radiumbox.com'), '/'),

    'timeout_seconds' => (int) env('RADIUMBOX_TIMEOUT_SECONDS', 5),

    'connect_timeout_seconds' => (int) env('RADIUMBOX_CONNECT_TIMEOUT_SECONDS', 3),

    'recovery' => [
        'enabled' => filter_var(env('RADIUMBOX_RECOVERY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'stale_pending_minutes' => (int) env('RADIUMBOX_STALE_PENDING_MINUTES', 30),
        'schedule_limit' => (int) env('RADIUMBOX_RECOVERY_SCHEDULE_LIMIT', 50),
        'max_recovery_attempts' => (int) env('RADIUMBOX_MAX_RECOVERY_ATTEMPTS', 10),
        'schedule_interval_minutes' => (int) env('RADIUMBOX_RECOVERY_INTERVAL_MINUTES', 15),
    ],
];
