<?php

return [
    'client_secret' => env('CASHFREE_CLIENT_SECRET'),
    'verify_signature' => filter_var(env('CASHFREE_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'system_user_email' => env('CASHFREE_SYSTEM_USER_EMAIL', 'superadmin@radium.local'),

    /*
    |--------------------------------------------------------------------------
    | Payment persistence contention retries
    |--------------------------------------------------------------------------
    |
    | Retries MySQL deadlock (1213) and lock-wait timeout (1205) while creating
    | Desk orders from PAYMENT_SUCCESS webhooks. Duplicate protection still runs
    | on every attempt via cashfree_payment_id / processed sibling checks.
    |
    */
    'persist_retry' => [
        'max_attempts' => max(1, (int) env('CASHFREE_PERSIST_RETRY_MAX_ATTEMPTS', 3)),
        'sleep_milliseconds' => max(0, (int) env('CASHFREE_PERSIST_RETRY_SLEEP_MS', 100)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic missing-order recovery
    |--------------------------------------------------------------------------
    |
    | Periodically replays only integrity-classified "recoverable" failed
    | PAYMENT_SUCCESS webhooks. Ira/admin are notified only when recovery fails.
    |
    */
    'auto_recover' => [
        'enabled' => filter_var(env('CASHFREE_AUTO_RECOVER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'schedule_interval_minutes' => max(1, (int) env('CASHFREE_AUTO_RECOVER_INTERVAL_MINUTES', 5)),
        'max_per_run' => max(1, (int) env('CASHFREE_AUTO_RECOVER_MAX_PER_RUN', 20)),
    ],
];
