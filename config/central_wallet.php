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
    | Central Customer identity resolution (default OFF — fail-closed)
    |--------------------------------------------------------------------------
    |
    | Desk Customer ID is the permanent central customer identity. Trusted
    | Google subject and verified email credentials resolve to one Customer ID
    | and one CWID. Mobile is optional and never the sole customer key.
    |
    */

    'customer_identity' => [
        'enabled' => filter_var(env('CENTRAL_WALLET_CUSTOMER_IDENTITY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'google_enabled' => filter_var(env('CENTRAL_WALLET_CUSTOMER_IDENTITY_GOOGLE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'verified_email_enabled' => filter_var(env('CENTRAL_WALLET_CUSTOMER_IDENTITY_VERIFIED_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'verified_mobile_enabled' => filter_var(env('CENTRAL_WALLET_CUSTOMER_IDENTITY_VERIFIED_MOBILE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'provisional_identity' => [
        'enabled' => filter_var(env('CENTRAL_WALLET_PROVISIONAL_IDENTITY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'financial_gate_enabled' => filter_var(env('CENTRAL_WALLET_PROVISIONAL_FINANCIAL_GATE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Terminal refund wallet migration (292 population — default OFF)
    |--------------------------------------------------------------------------
    */

    'refund_migration' => [
        'execution_enabled' => filter_var(env('CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'manifest_path' => env(
            'CENTRAL_WALLET_REFUND_MIGRATION_MANIFEST_PATH',
            storage_path('app/private/cw-migration-manifest-final-p30-09-25.json'),
        ),
        'expected_count' => 292,
        'expected_amount' => '165708.00',
        'batch_id' => 'desk-refund-wallet-migration-292-p30-09-25',
    ],

    /*
    |--------------------------------------------------------------------------
    | TYPE-1 migration cohort identity (50 customers — default OFF)
    |--------------------------------------------------------------------------
    |
    | Owner-authorized server-side identity establishment for the immutable
    | 50-customer TYPE-1 pilot cohort. Does NOT require Connect Wallet OTP.
    | Does NOT move money. Does NOT enable refund migration execution.
    |
    */

    'type1_migration_cohort' => [
        'identity_establishment_enabled' => filter_var(
            env('CENTRAL_WALLET_TYPE1_MIGRATION_COHORT_IDENTITY_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'cohort_manifest_path' => env(
            'CENTRAL_WALLET_TYPE1_MIGRATION_COHORT_MANIFEST_PATH',
            storage_path('app/private/cw-type1-migration-cohort-p30-10-04.json'),
        ),
        'cohort_id' => 'type1-migration-cohort-50-p30-10-04',
        'expected_customers' => 50,
        'expected_refunds' => 50,
        'expected_amount' => '26230.00',
    ],

];
