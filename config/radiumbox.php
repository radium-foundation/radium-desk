<?php

return [
    'enabled' => filter_var(env('RADIUMBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'base_url' => rtrim((string) env('RADIUMBOX_BASE_URL', ''), '/'),

    /*
     * Retired. Production must stay false so Desk never calls
     * admin.radiumbox.com. Tests that still exercise the old client opt in.
     */
    'admin_fallback_enabled' => filter_var(env('RADIUMBOX_ADMIN_FALLBACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'timeout_seconds' => (int) env('RADIUMBOX_TIMEOUT_SECONDS', 5),

    'connect_timeout_seconds' => (int) env('RADIUMBOX_CONNECT_TIMEOUT_SECONDS', 3),

    'recovery' => [
        'enabled' => filter_var(env('RADIUMBOX_RECOVERY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'stale_pending_minutes' => (int) env('RADIUMBOX_STALE_PENDING_MINUTES', 30),
        'schedule_limit' => (int) env('RADIUMBOX_RECOVERY_SCHEDULE_LIMIT', 50),
        'max_recovery_attempts' => (int) env('RADIUMBOX_MAX_RECOVERY_ATTEMPTS', 10),
        'schedule_interval_minutes' => (int) env('RADIUMBOX_RECOVERY_INTERVAL_MINUTES', 15),
    ],

    'auto_sync' => [
        'enabled' => filter_var(env('RADIUMBOX_AUTO_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'min_interval_minutes' => (int) env('RADIUMBOX_AUTO_SYNC_MIN_INTERVAL_MINUTES', 30),
    ],

    /*
    | Short-lived cache for background (queue) order lookups. Dedupes recovery /
    | duplicate-job HTTP within the TTL. Set 0 to disable. Retriable failures
    | are never cached.
    */
    'background_lookup_cache_seconds' => (int) env('RADIUMBOX_BACKGROUND_LOOKUP_CACHE_SECONDS', 300),
];
