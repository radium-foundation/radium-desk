<?php

return [
    'api_key' => env('INTERAKT_API_KEY'),
    'webhook_secret' => env('INTERAKT_WEBHOOK_SECRET'),
    'base_url' => rtrim((string) env('INTERAKT_BASE_URL', 'https://api.interakt.ai'), '/'),
    'verify_signature' => filter_var(env('INTERAKT_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'timeout_seconds' => (int) env('INTERAKT_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (int) env('INTERAKT_CONNECT_TIMEOUT_SECONDS', 5),
    'max_retries' => (int) env('INTERAKT_MAX_RETRIES', 3),
    'retry_delay_ms' => (int) env('INTERAKT_RETRY_DELAY_MS', 200),
    'app_url' => rtrim((string) env('INTERAKT_APP_URL', 'https://app.interakt.ai'), '/'),
    'conversation_url_template' => env('INTERAKT_CONVERSATION_URL_TEMPLATE'),
    'customer_profile_url_template' => env(
        'INTERAKT_CUSTOMER_PROFILE_URL_TEMPLATE',
        '{app_url}/contacts?search={phone}',
    ),
];
