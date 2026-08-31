<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RDService.net order API (read-only enrichment)
    |--------------------------------------------------------------------------
    |
    | Cashfree remains the payment source of truth. This client only enriches
    | Desk orders after payment ingest. Host is config-only (never request-
    | controlled). HTTPS is required. Token is never logged.
    |
    */

    /*
     * Production default is off so deploying this config does not change the
     * live Admin /api/search/order enrichment path. Activation is a separate
     * gate: set RDSERVICE_ENABLED=true and a verified DESK_ORDER_API_TOKEN.
     */
    'enabled' => filter_var(env('RDSERVICE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'base_url' => rtrim((string) env('RDSERVICE_BASE_URL', 'https://rdservice.net'), '/'),

    'token' => env('DESK_ORDER_API_TOKEN'),

    'connect_timeout_seconds' => max(1, (int) env('RDSERVICE_CONNECT_TIMEOUT_SECONDS', 3)),

    'timeout_seconds' => max(1, (int) env('RDSERVICE_TIMEOUT_SECONDS', 8)),

];
