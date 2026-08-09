<?php

return [
    'client_secret' => env('CASHFREE_CLIENT_SECRET'),
    'verify_signature' => filter_var(env('CASHFREE_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'system_user_email' => env('CASHFREE_SYSTEM_USER_EMAIL', 'superadmin@radium.local'),

    /*
    |--------------------------------------------------------------------------
    | Cashfree Payments API (read-only)
    |--------------------------------------------------------------------------
    |
    | Used by one-time missed-webhook heal / external reconciliation. Separate
    | from CASHFREE_CLIENT_SECRET (webhook HMAC only). GET order + payments only.
    |
    */
    'api' => [
        'app_id' => env('CASHFREE_APP_ID'),
        'secret' => env('CASHFREE_API_SECRET'),
        'base_url' => env('CASHFREE_API_BASE_URL', 'https://api.cashfree.com/pg'),
        'version' => env('CASHFREE_API_VERSION', '2026-01-01'),
        'connect_timeout_seconds' => max(1, (int) env('CASHFREE_API_CONNECT_TIMEOUT_SECONDS', 5)),
        'timeout_seconds' => max(1, (int) env('CASHFREE_API_TIMEOUT_SECONDS', 15)),
    ],

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
        'schedule_interval_minutes' => max(1, (int) env('CASHFREE_AUTO_RECOVER_INTERVAL_MINUTES', 15)),
        'max_per_run' => max(1, (int) env('CASHFREE_AUTO_RECOVER_MAX_PER_RUN', 20)),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time Aug 7 missed-webhook batch heal
    |--------------------------------------------------------------------------
    */
    'missed_batch_heal' => [
        'batch_id' => 'aug7-2026-missed-webhook',
        'lock_seconds' => max(30, (int) env('CASHFREE_MISSED_BATCH_HEAL_LOCK_SECONDS', 120)),
        'allowlist' => [
            'RD3478381',
            'RD3478382',
            'RD3478386',
            'RD3478387',
            'RD3478388',
            'RD3478391',
            'RD3478397',
        ],
    ],
];
