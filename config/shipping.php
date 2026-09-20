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

    /*
    | Read-only Shiprocket wallet balance cache. Defaults to the courier-options TTL
    | so balance is not fetched on every Hardware dashboard page load.
    */
    'wallet_balance_ttl_seconds' => max(60, (int) env('SHIPROCKET_WALLET_BALANCE_TTL_SECONDS', 900)),

    /*
    | Owner-tunable low-balance warning threshold (INR). Informational only in
    | Phase 1 — does not block shipping actions. Default is a conservative buffer
    | above typical single-shipment freight; confirm with Owner before production.
    */
    'wallet_balance_low_threshold' => (string) env('SHIPROCKET_WALLET_BALANCE_LOW_THRESHOLD', '1000.00'),

    'timeout_seconds' => max(1, (int) env('SHIPROCKET_TIMEOUT_SECONDS', 15)),

    /*
    | Connect timeout stays 5s. Production DNS failures fail at the resolver
    | (cURL 28 after exactly this budget). Healthy apiv2 namelookup is ~8ms.
    | Raising the default would only delay the same outage; it is not a DNS fix.
    */
    'connect_timeout_seconds' => max(1, (int) env('SHIPROCKET_CONNECT_TIMEOUT_SECONDS', 5)),

    /*
    | Shared auth-token cache. Login tokens last 86400s at Shiprocket; a margin
    | is subtracted so an about-to-expire token is not reused. Cache miss/failure
    | falls back to a normal login. This reduces auth chatter; it does not fix DNS.
    */
    'token_ttl_seconds' => max(1, (int) env('SHIPROCKET_TOKEN_TTL_SECONDS', 86400)),

    'token_expiry_margin_seconds' => max(0, (int) env('SHIPROCKET_TOKEN_EXPIRY_MARGIN_SECONDS', 120)),

    /*
    | Extra login attempts after a connectivity/DNS timeout only. 0 = no retry.
    | Bounded to 2 extra attempts. Never retries AWB assignment.
    */
    'auth_connect_retries' => max(0, min(2, (int) env('SHIPROCKET_AUTH_CONNECT_RETRIES', 1))),

    /*
    | Ordered Desk courier preference. Empty means no configured preference.
    | Values come from env only. Do not invent IDs in application code.
    */
    'preferred_courier_ids' => array_values(array_unique(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('SHIPROCKET_PREFERRED_COURIER_IDS', '')),
    ), static fn (string $id): bool => $id !== ''))),

    /*
    | Default false. The HTTP client exists but stays unbound unless this is
    | true, shipping is enabled, provider is shiprocket, and credentials exist.
    | Isolated one-order fulfilment may instantiate the client in-process.
    */
    'http_enabled' => filter_var(env('SHIPROCKET_HTTP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
