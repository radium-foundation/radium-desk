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
];
