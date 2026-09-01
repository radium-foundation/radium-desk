<?php

return [
    'webhook_token' => env('BONVOICE_WEBHOOK_TOKEN'),
    'account_id' => env('BONVOICE_ACCOUNT_ID'),
    'verify_webhook_auth' => filter_var(env('BONVOICE_VERIFY_WEBHOOK_AUTH', false), FILTER_VALIDATE_BOOLEAN),
    'require_bearer' => filter_var(env('BONVOICE_REQUIRE_BEARER', false), FILTER_VALIDATE_BOOLEAN),
    // Deprecated: prefer BONVOICE_VERIFY_WEBHOOK_AUTH and BONVOICE_REQUIRE_BEARER.
    'verify_signature' => filter_var(env('BONVOICE_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'missed_call_recovery_enabled' => filter_var(
        env('BONVOICE_MISSED_CALL_RECOVERY_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'auto_open_customer360' => filter_var(
        env('BONVOICE_AUTO_OPEN_CUSTOMER360', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'incoming_latency_log' => filter_var(
        env('BONVOICE_INCOMING_LATENCY_LOG', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Call-event write contention retries
    |--------------------------------------------------------------------------
    |
    | Concurrent BonVoice lifecycle webhooks for the same call_id+leg can hit
    | MariaDB 1020 (record changed since last read) or 1213 (deadlock) while
    | upserting bonvoice_call_events. The persist transaction is retried in a
    | new unit of work. Outbox retry remains the safety net if attempts exhaust.
    |
    */
    'call_event_write_retry' => [
        'max_attempts' => max(1, (int) env('BONVOICE_CALL_EVENT_WRITE_RETRY_MAX_ATTEMPTS', 5)),
        'sleep_milliseconds' => max(0, (int) env('BONVOICE_CALL_EVENT_WRITE_RETRY_SLEEP_MS', 25)),
    ],
];
