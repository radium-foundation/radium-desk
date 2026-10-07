<?php

use App\CentralWallet\Support\CohortUserIdList;

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

    'ceremony' => [
        'audience' => 'radium-desk:ceremony-complete',
        'proof_ttl_seconds' => max(60, (int) env('CENTRAL_WALLET_CEREMONY_PROOF_TTL_SECONDS', 300)),
        'cross_site_enabled' => filter_var(env('CENTRAL_WALLET_CROSS_SITE_CEREMONY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'cross_site_cohort' => [
            'enabled' => filter_var(env('CENTRAL_WALLET_CROSS_SITE_CEREMONY_COHORT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'allowed_local_user_ids' => CohortUserIdList::normalize(
                env('CENTRAL_WALLET_CROSS_SITE_CEREMONY_COHORT_LOCAL_USER_IDS', ''),
            ),
        ],
        'signing_secrets' => [
            'radiumbox.com' => env('CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_RADIUMBOX_COM'),
            'rdservice.in' => env('CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_RDSERVICE_IN'),
        ],
    ],

    'reservation_ttl_seconds' => max(60, (int) env('CENTRAL_WALLET_RESERVATION_TTL_SECONDS', 900)),

    'reservations' => [
        'enabled' => filter_var(env('CENTRAL_WALLET_RESERVATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'expiry_batch_size' => max(1, (int) env('CENTRAL_WALLET_RESERVATION_EXPIRY_BATCH_SIZE', 100)),
        'expiry_schedule' => env('CENTRAL_WALLET_RESERVATION_EXPIRY_SCHEDULE', '*/15 * * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | External site direct ledger debit gate
    |--------------------------------------------------------------------------
    |
    | Controls whether authenticated external site callers (X-Site-Code) may
    | POST entry_type=debit to /wallets/{cwid}/ledger-entries.
    |
    | Default true — preserves current production behavior until checkout cutover
    | explicitly sets this to false and enables the reservation spend path.
    |
    | Does NOT gate: reservation commit debits, credits, adjustments, reversals,
    | balance migration, or internal central_wallet_service callers.
    |
    */

    'direct_ledger_debit' => [
        'enabled' => filter_var(env('CENTRAL_WALLET_DIRECT_LEDGER_DEBIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

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
        'customer_history' => [
            'enabled' => filter_var(env('CENTRAL_WALLET_CUSTOMER_HISTORY_READ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'authorized_callers' => array_values(array_filter(array_map(
                static fn (string $site): string => trim($site),
                explode(',', (string) env(
                    'CENTRAL_WALLET_CUSTOMER_HISTORY_AUTHORIZED_CALLERS',
                    'radiumbox.com,rdservice.in,rdservice.net',
                )),
            ))),
            'authorized_source_systems' => array_values(array_filter(array_map(
                static fn (string $site): string => trim($site),
                explode(',', (string) env(
                    'CENTRAL_WALLET_CUSTOMER_HISTORY_AUTHORIZED_SOURCE_SYSTEMS',
                    'radiumbox.com,rdservice.in,rdservice.net',
                )),
            ))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'log_channel' => env('CENTRAL_WALLET_LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Balance migration / cutover (default OFF — fail-closed)
    |--------------------------------------------------------------------------
    */

    'balance_migration' => [
        'execution_enabled' => filter_var(env('CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'spoke_base_url' => env('CENTRAL_WALLET_MIGRATION_SPOKE_BASE_URL'),
        'spoke_token' => env('CENTRAL_WALLET_MIGRATION_SPOKE_TOKEN'),
        'spoke_connect_timeout_seconds' => max(1, (int) env('CENTRAL_WALLET_MIGRATION_SPOKE_CONNECT_TIMEOUT', 3)),
        'spoke_timeout_seconds' => max(1, (int) env('CENTRAL_WALLET_MIGRATION_SPOKE_TIMEOUT', 15)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Historical wallet visibility (contact index for refund destination)
    |--------------------------------------------------------------------------
    */

    'historical_wallet_visibility' => [
        'enabled' => filter_var(
            env('CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'protected_refund_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('CENTRAL_WALLET_HISTORICAL_PROTECTED_REFUND_IDS', '')),
        ))),
        'campaign_start_date' => '2026-07-15',
        'contact_match_enabled' => filter_var(
            env('CENTRAL_WALLET_HISTORICAL_CONTACT_MATCH_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'contact_index_manifest_path' => env(
            'CENTRAL_WALLET_HISTORICAL_CONTACT_INDEX_MANIFEST_PATH',
            storage_path('app/private/cw-historical-visibility-contact-index.json'),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet refund destination (CWID resolve for spoke wallet credits)
    |--------------------------------------------------------------------------
    */

    'wallet_refund_destination' => [
        'enabled' => filter_var(
            env('CENTRAL_WALLET_WALLET_REFUND_DESTINATION_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer identity ensure (canonical CWID for refund destination)
    |--------------------------------------------------------------------------
    */

    'customer_identity_ensure' => [
        'enabled' => filter_var(
            env('CENTRAL_WALLET_CUSTOMER_IDENTITY_ENSURE_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

];
