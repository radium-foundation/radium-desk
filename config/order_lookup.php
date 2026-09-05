<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Order-source routing (Desk → spoke APIs)
    |--------------------------------------------------------------------------
    |
    | Old Admin is not a live fallback. When a spoke is unconfigured the
    | class is UNSUPPORTED, not routed to admin.radiumbox.com.
    |
    */

    'admin_fallback_enabled' => filter_var(env('RADIUMBOX_ADMIN_FALLBACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'spokes' => [
        'rdservice_in' => [
            'enabled' => filter_var(env('RDSERVICE_IN_LOOKUP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'base_url' => rtrim((string) env('RDSERVICE_IN_BASE_URL', 'https://rdservice.in'), '/'),
            'host' => trim((string) env('RDSERVICE_IN_HOST', '')),
            'token' => env('RDSERVICE_IN_DESK_TOKEN', env('DESK_ORDER_API_TOKEN')),
            'connect_timeout_seconds' => max(1, (int) env('RDSERVICE_IN_CONNECT_TIMEOUT_SECONDS', 3)),
            'timeout_seconds' => max(1, (int) env('RDSERVICE_IN_TIMEOUT_SECONDS', 8)),
            'accepts' => ['rd', 'rin'],
        ],
        'radiumbox_com' => [
            'enabled' => filter_var(env('RADIUMBOX_STOREFRONT_LOOKUP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'base_url' => rtrim((string) env('RADIUMBOX_STOREFRONT_BASE_URL', 'https://radiumbox.com'), '/'),
            'host' => trim((string) env('RADIUMBOX_STOREFRONT_HOST', '')),
            'token' => env('RADIUMBOX_STOREFRONT_DESK_TOKEN', env('DESK_ORDER_API_TOKEN')),
            'connect_timeout_seconds' => max(1, (int) env('RADIUMBOX_STOREFRONT_CONNECT_TIMEOUT_SECONDS', 3)),
            'timeout_seconds' => max(1, (int) env('RADIUMBOX_STOREFRONT_TIMEOUT_SECONDS', 8)),
            'accepts' => ['rd', 'rde'],
            'historical_invoice_path' => '/api/integrations/v1/historical-invoices/',
        ],
    ],

];
