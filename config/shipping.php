<?php

return [

    /*
    | Default false. Enabling this flag is not enough to call a live API:
    | provider must not be `none`, credentials must exist, and a real HTTP
    | adapter must be bound. P5 binds NullShiprocketGateway.
    */
    'enabled' => filter_var(env('SHIPROCKET_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'provider' => env('SHIPROCKET_PROVIDER', 'none'),

    'base_url' => rtrim((string) env('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in/v1/external'), '/'),

    'api_email' => env('SHIPROCKET_API_EMAIL'),

    'api_password' => env('SHIPROCKET_API_PASSWORD'),

    'channel_id' => env('SHIPROCKET_CHANNEL_ID'),

    /*
    | Owner-confirmed panel nicknames (P0-M3). Values come from env only:
    | Delhi stock = RADDELHI, Mumbai stock = RADIUMUM. Do not hardcode.
    */
    'pickup_locations' => [
        'delhi' => env('SHIPROCKET_PICKUP_DELHI'),
        'mumbai' => env('SHIPROCKET_PICKUP_MUMBAI'),
    ],

    /*
    | Warehouse pincodes for GET /courier/serviceability/. Empty until set in
    | env. Do not invent production values. Courier options fail closed if empty.
    */
    'pickup_postcodes' => [
        'delhi' => env('SHIPROCKET_PICKUP_PINCODE_DELHI'),
        'mumbai' => env('SHIPROCKET_PICKUP_PINCODE_MUMBAI'),
    ],

    'courier_options_ttl_seconds' => max(60, (int) env('SHIPROCKET_COURIER_OPTIONS_TTL_SECONDS', 900)),

    'timeout_seconds' => max(1, (int) env('SHIPROCKET_TIMEOUT_SECONDS', 15)),

    'connect_timeout_seconds' => max(1, (int) env('SHIPROCKET_CONNECT_TIMEOUT_SECONDS', 5)),

    /*
    | Default false. The HTTP client exists but stays unbound unless this is
    | true, shipping is enabled, provider is shiprocket, and credentials exist.
    | Isolated one-order fulfilment may instantiate the client in-process.
    */
    'http_enabled' => filter_var(env('SHIPROCKET_HTTP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
