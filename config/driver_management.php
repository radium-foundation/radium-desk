<?php

return [

    'radiumbox_com' => [
        'enabled' => filter_var(env('RADIUMBOX_DRIVER_MANAGEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('RADIUMBOX_STOREFRONT_BASE_URL', 'https://radiumbox.com'), '/'),
        'host' => trim((string) env('RADIUMBOX_STOREFRONT_HOST', '')),
        'token' => env('RADIUMBOX_STOREFRONT_DESK_TOKEN', env('DESK_ORDER_API_TOKEN')),
        'connect_timeout_seconds' => max(1, (int) env('RADIUMBOX_STOREFRONT_CONNECT_TIMEOUT_SECONDS', 3)),
        'timeout_seconds' => max(1, (int) env('RADIUMBOX_STOREFRONT_TIMEOUT_SECONDS', 30)),
        'site_code' => 'radiumbox_com',
    ],

];
