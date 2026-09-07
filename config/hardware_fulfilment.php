<?php

return [

    /*
    | When false (default), Cashfree OrderPaid does not write hardware payment
    | evidence. P2 keeps this off so live RDE* webhooks, including the seven
    | frozen orders, are not processed by the fulfilment workflow.
    */
    'correlate_cashfree' => false,

    /*
    | Owner-approved Box model_id → Desk inventory_products.id rows live in
    | channel_sku_maps. This array stays empty until P0-M1 is supplied.
    | Do not invent mappings in code.
    */
    'sku_map' => [],

    /*
    | Desk → radiumbox.com fulfilment callback. Default off. Empty URL/secret
    | means the worker must not call HTTP. Do not copy Admin/Box secrets.
    */
    'callback' => [
        'enabled' => filter_var(env('HARDWARE_FULFILMENT_CALLBACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'inbound_enabled' => filter_var(env('HARDWARE_FULFILMENT_CALLBACK_INBOUND_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'url' => env('HARDWARE_FULFILMENT_CALLBACK_URL', ''),
        'secret' => env('DESK_CALLBACK_SECRET', ''),
        'replay_window_seconds' => max(30, (int) env('HARDWARE_FULFILMENT_CALLBACK_REPLAY_WINDOW', 300)),
        'timeout_seconds' => max(1, (int) env('HARDWARE_FULFILMENT_CALLBACK_TIMEOUT_SECONDS', 10)),
    ],

    /*
    | Extra owner-HOLD source ids. RDE318438 is also hardcoded.
    | Isolated fulfilment refuses these. Do not use this as a batch list.
    */
    'hold_source_ids' => array_values(array_filter(array_map(
        static fn (string $id): string => strtoupper(trim($id)),
        explode(',', (string) env('HARDWARE_FULFILMENT_HOLD_SOURCE_IDS', '')),
    ))),

];
