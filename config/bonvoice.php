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
    'click_to_call' => [
        'enabled' => filter_var(env('BONVOICE_CLICK_TO_CALL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('BONVOICE_API_BASE_URL', 'https://backend.pbx.bonvoice.com'), '/'),
        'username' => env('BONVOICE_API_USERNAME'),
        'password' => env('BONVOICE_API_PASSWORD'),
        'did' => env('BONVOICE_DID', '8040837125'),
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
    ],
];
