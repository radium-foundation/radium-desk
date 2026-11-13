<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Morpho OTBS adapter driver
    |--------------------------------------------------------------------------
    |
    | mock — synthetic transport only (default, safe for all environments).
    | live — reserved for future OEM-authorized credentials; fails closed when
    |        credentials are absent or live integration is not explicitly enabled.
    |
    */
    'driver' => env('MORPHO_OTBS_ADAPTER_DRIVER', 'mock'),

    'live' => [
        'enabled' => (bool) env('MORPHO_OTBS_LIVE_ENABLED', false),
        'validator_base_url' => env('MORPHO_OTBS_VALIDATOR_BASE_URL', ''),
        'intermediate_base_url' => env('MORPHO_OTBS_INTERMEDIATE_BASE_URL', ''),
        'authorization' => env('MORPHO_OTBS_AUTHORIZATION', ''),
        'security_stamp' => env('MORPHO_OTBS_SECURITY_STAMP', ''),
    ],

    'http' => [
        'timeout_seconds' => max(1, (int) env('MORPHO_OTBS_HTTP_TIMEOUT_SECONDS', 10)),
        'connect_timeout_seconds' => max(1, (int) env('MORPHO_OTBS_HTTP_CONNECT_TIMEOUT_SECONDS', 5)),
        'retry_times' => max(0, min(2, (int) env('MORPHO_OTBS_HTTP_RETRY_TIMES', 1))),
    ],

    'logging' => [
        'redact_serials' => true,
    ],
];
