<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central Wallet feature flags (default OFF — Phase 1 safety)
    |--------------------------------------------------------------------------
    */

    'enabled' => filter_var(env('CENTRAL_WALLET_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'api_enabled' => filter_var(env('CENTRAL_WALLET_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'reconciliation' => [
        'enabled' => filter_var(env('CENTRAL_WALLET_RECONCILIATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'daily_schedule' => env('CENTRAL_WALLET_RECONCILIATION_DAILY_AT', '02:30'),
        'batch_size' => max(1, (int) env('CENTRAL_WALLET_RECONCILIATION_BATCH_SIZE', 100)),
        'rate_limit_per_minute' => max(1, (int) env('CENTRAL_WALLET_RECONCILIATION_RATE_LIMIT', 60)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service authentication (fail-closed when token empty)
    |--------------------------------------------------------------------------
    */

    'integration_token' => env('CENTRAL_WALLET_INTEGRATION_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Identity & ledger
    |--------------------------------------------------------------------------
    */

    'idempotency_retention_days' => max(1, (int) env('CENTRAL_WALLET_IDEMPOTENCY_RETENTION_DAYS', 90)),

    'reservation_ttl_seconds' => max(60, (int) env('CENTRAL_WALLET_RESERVATION_TTL_SECONDS', 900)),

    'currency' => 'INR',

    /*
    |--------------------------------------------------------------------------
    | Read-only ledger query API (Desk-R4.a)
    |--------------------------------------------------------------------------
    */

    'ledger_read' => [
        'default_page_size' => max(1, (int) env('CENTRAL_WALLET_LEDGER_READ_DEFAULT_PAGE_SIZE', 100)),
        'max_page_size' => max(1, (int) env('CENTRAL_WALLET_LEDGER_READ_MAX_PAGE_SIZE', 500)),
        'max_date_range_days' => max(1, (int) env('CENTRAL_WALLET_LEDGER_READ_MAX_DATE_RANGE_DAYS', 31)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'log_channel' => env('CENTRAL_WALLET_LOG_CHANNEL', 'stack'),

];
