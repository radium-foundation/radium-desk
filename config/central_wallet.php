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
        'wallet_refund_detection_enabled' => filter_var(
            env('CENTRAL_WALLET_RECONCILIATION_WALLET_REFUND_DETECTION_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
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
    | Historical wallet visibility (post-2026-07-15 refunds — default OFF)
    |--------------------------------------------------------------------------
    |
    | Read-only customer-facing display of historical wallet refund balances.
    | Does NOT credit Central Wallet, debit spokes, or enable spending without
    | trusted identity. Desk Central Wallet remains SSOT at checkout.
    |
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
    | Wallet refund destination identity (canonical email/mobile match)
    |--------------------------------------------------------------------------
    |
    | Resolves CWID for wallet refund credits. Verification and spoke trusted
    | links are not required; spending authorization remains separate.
    |
    */

    'wallet_refund_destination' => [
        'enabled' => filter_var(
            env('CENTRAL_WALLET_WALLET_REFUND_DESTINATION_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer identity → CWID ensure (default ON)
    |--------------------------------------------------------------------------
    |
    | Ensures valid spoke customers resolve to exactly one Desk Central Wallet.
    | Used by wallet-refund-destination and integration ensure paths.
    | Does NOT authorize spending — trusted verification remains separate.
    |
    */

    'customer_identity_ensure' => [
        'enabled' => filter_var(
            env('CENTRAL_WALLET_CUSTOMER_IDENTITY_ENSURE_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | IDENTITY_REQUIRED historical cohort (220 refunds — default OFF)
    |--------------------------------------------------------------------------
    |
    | Read-only provisional display of local spoke wallet balances for the
    | immutable 220-refund IDENTITY_REQUIRED cohort (P-30-10-16 audit).
    | Unverified contact data may display balance only; financial use requires
    | trusted verification. Does NOT move money or auto-create identity.
    |
    */

    'identity_required_cohort' => [
        'provisional_display_enabled' => filter_var(
            env('CENTRAL_WALLET_IDENTITY_REQUIRED_COHORT_PROVISIONAL_DISPLAY_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'campaign_manifest_path' => env(
            'CENTRAL_WALLET_IDENTITY_REQUIRED_COHORT_MANIFEST_PATH',
            storage_path('app/private/cw-remaining-239-campaign-p30-10-15.json'),
        ),
        'cohort_id' => 'identity-required-220-p30-10-16',
        'expected_refunds' => 220,
        'expected_amount' => '127328.00',
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

    /*
    |--------------------------------------------------------------------------
    | TYPE-1 financial migration preparation (50 refunds — default OFF)
    |--------------------------------------------------------------------------
    */

    'type1_financial_migration' => [
        'manifest_path' => env(
            'CENTRAL_WALLET_TYPE1_FINANCIAL_MIGRATION_MANIFEST_PATH',
            storage_path('app/private/cw-type1-financial-migration-preflight-p30-10-06.json'),
        ),
        'rdin_env_path' => env('CENTRAL_WALLET_TYPE1_RDIN_ENV_PATH', '/var/www/rdservice.in/.env'),
        'rdin_db_user' => env('CENTRAL_WALLET_TYPE1_RDIN_DB_USER', 'rdservice_in_prod'),
        'rdin_db_name' => env('CENTRAL_WALLET_TYPE1_RDIN_DB_NAME', 'rdservice_in_prod'),
    ],

    /*
    |--------------------------------------------------------------------------
    | READY-4 financial migration preflight (4 refunds — default OFF)
    |--------------------------------------------------------------------------
    */

    'ready4_financial_migration' => [
        'manifest_path' => env(
            'CENTRAL_WALLET_READY4_FINANCIAL_MIGRATION_MANIFEST_PATH',
            storage_path('app/private/cw-type1-ready4-financial-preflight-p30-10-12.json'),
        ),
        'rdin_env_path' => env('CENTRAL_WALLET_READY4_RDIN_ENV_PATH', '/var/www/rdservice.in/.env'),
        'rdin_db_user' => env('CENTRAL_WALLET_READY4_RDIN_DB_USER', 'rdservice_in_prod'),
        'rdin_db_name' => env('CENTRAL_WALLET_READY4_RDIN_DB_NAME', 'rdservice_in_prod'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Refund 360 / radiumbox.com Lane-1 migration (1 refund — default OFF)
    |--------------------------------------------------------------------------
    */

    'refund360_migration' => [
        'manifest_path' => env(
            'CENTRAL_WALLET_REFUND360_MIGRATION_MANIFEST_PATH',
            storage_path('app/private/cw-refund360-migration-manifest-p30-10-26.json'),
        ),
        'radiumbox_env_path' => env('CENTRAL_WALLET_REFUND360_RADIUMBOX_ENV_PATH', '/var/www/radiumbox.com/.env'),
        'radiumbox_db_user' => env('CENTRAL_WALLET_REFUND360_RADIUMBOX_DB_USER', 'radiumbox_prod'),
        'radiumbox_db_name' => env('CENTRAL_WALLET_REFUND360_RADIUMBOX_DB_NAME', 'radiumbox_prod'),
    ],

    /*
    |--------------------------------------------------------------------------
    | E-2 historical manual refund settlement (52 refunds — default OFF)
    |--------------------------------------------------------------------------
    |
    | Owner-approved settlement for E-2 cohort where historical spoke wallet
    | destination could not be reconstructed (P-30-10-19). Credits Central
    | Wallet only — no spoke debit, no fabricated source provenance.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Next-safe batch preparation (remaining-239 — default OFF execution)
    |--------------------------------------------------------------------------
    */

    'next_safe_batch' => [
        'manifest_path' => env(
            'CENTRAL_WALLET_NEXT_SAFE_BATCH_MANIFEST_PATH',
            storage_path('app/private/cw-next-safe-batch-manifest-p30-10-24.json'),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | E-1 identity migration (168 refunds — default OFF)
    |--------------------------------------------------------------------------
    |
    | Trusted identity + destination preparation for E-1 historical wallet refunds.
    | Does NOT credit Central Wallet or debit spoke wallets.
    |
    */

    'e1_identity_migration' => [
        'verification_cohort_manifest_path' => env(
            'CENTRAL_WALLET_E1_VERIFICATION_COHORT_MANIFEST_PATH',
            storage_path('app/private/cw-e1-verification-cohort-manifest-p30-10-32.json'),
        ),
        'destination_readiness_manifest_path' => env(
            'CENTRAL_WALLET_E1_DESTINATION_READINESS_MANIFEST_PATH',
            storage_path('app/private/cw-e1-destination-readiness-manifest-p30-10-32.json'),
        ),
        'expected_count' => 168,
        'expected_amount' => '92811.00',
        'batch_id' => 'desk-refund-identity-migration-e1-168-p30-10-32',
        'owner_approval_ref' => env(
            'CENTRAL_WALLET_E1_IDENTITY_MIGRATION_OWNER_APPROVAL_REF',
            'OWNER-CW-E1-IDENTITY-MIGRATION-20261001-001',
        ),
        'verification_enabled' => filter_var(
            env('CENTRAL_WALLET_E1_IDENTITY_MIGRATION_VERIFICATION_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    'e2_historical_settlement' => [
        'manifest_path' => env(
            'CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_MANIFEST_PATH',
            storage_path('app/private/cw-e2-historical-settlement-manifest-p30-10-20.json'),
        ),
        'verification_cohort_manifest_path' => env(
            'CENTRAL_WALLET_E2_VERIFICATION_COHORT_MANIFEST_PATH',
            storage_path('app/private/cw-e2-verification-cohort-manifest-p30-10-22.json'),
        ),
        'destination_readiness_manifest_path' => env(
            'CENTRAL_WALLET_E2_DESTINATION_READINESS_MANIFEST_PATH',
            storage_path('app/private/cw-e2-destination-readiness-manifest-p30-10-29.json'),
        ),
        'expected_count' => 52,
        'expected_amount' => '34517.00',
        'batch_id' => 'desk-refund-historical-settlement-e2-52-p30-10-20',
        'owner_approval_ref' => env(
            'CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_OWNER_APPROVAL_REF',
            'OWNER-CW-E2-HISTORICAL-SETTLEMENT-20261001-001',
        ),
        'verification_enabled' => filter_var(
            env('CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_VERIFICATION_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'execution_enabled' => filter_var(
            env('CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_EXECUTION_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

];
