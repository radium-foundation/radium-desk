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

    /*
    | rdservice.in RIN hardware. Stub storefront product 1027 is never a model id.
    | sku_maps are Owner-approved (channel, model_id) → Desk inventory SKU rows
    | applied by desk:seed-rdservice-in-hardware-sku-maps. Do not invent extras.
    */
    'rdservice_in' => [
        'forbidden_model_ids' => [1027],
        'sku_maps' => [
            [
                'model_id' => 91001,
                'channel_sku' => 'mantra-fingerprint',
                'desk_sku' => 'RBMFS110L1',
                'notes' => 'RIN mantra-fingerprint → Desk MFS110 L1',
            ],
            [
                'model_id' => 91002,
                'channel_sku' => 'mantra-iris',
                'desk_sku' => 'RBMIS100IR',
                'notes' => 'RIN mantra-iris → Desk MIS100',
            ],
            [
                'model_id' => 91003,
                'channel_sku' => 'morpho-fingerprint',
                'desk_sku' => 'RBIMSOE3L1',
                'notes' => 'RIN morpho-fingerprint → Desk Morpho MSO 1300 E3',
            ],
            [
                'model_id' => 91004,
                'channel_sku' => 'startek-fingerprint',
                'desk_sku' => 'RBFM220UFP',
                'notes' => 'RIN startek-fingerprint → Desk FM220 USB (not Type-C scanner SKU)',
            ],
        ],
    ],

];
