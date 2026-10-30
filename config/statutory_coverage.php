<?php

return [

    'timezone' => env('STATUTORY_COVERAGE_TIMEZONE', 'Asia/Kolkata'),

    'default_source_type' => 'commerce_order',

    /*
    | Registered statutory channels reconciled by desk:reconcile-statutory-coverage.
    | obligation_list_path: spoke HTTP path for paginated obligations; null = unavailable.
    */
    'channels' => [
        'rdservice_in' => [
            'label' => 'rdservice.in',
            'spoke' => 'rdservice_in',
            'obligation_list_path' => '/api/integrations/v1/statutory-obligations',
            'spoke_sync_capable' => false,
            'desk_paid_support_orders' => true,
        ],
        'radiumbox_com' => [
            'label' => 'radiumbox.com',
            'spoke' => 'radiumbox_com',
            'obligation_list_path' => '/api/integrations/v1/statutory-obligations',
            'spoke_sync_capable' => true,
            'desk_paid_support_orders' => false,
        ],
        'rdservice_net' => [
            'label' => 'rdservice.net',
            'spoke' => 'rdservice_net',
            'obligation_list_path' => null,
            'spoke_sync_capable' => false,
            'desk_paid_support_orders' => true,
        ],
        'radiumsign_com' => [
            'label' => 'radiumsign.com',
            'spoke' => null,
            'obligation_list_path' => null,
            'spoke_sync_capable' => false,
            'desk_paid_support_orders' => false,
            'observe_only' => true,
        ],
    ],

    'pagination' => [
        'default_per_page' => 500,
        'max_per_page' => 2000,
    ],

    'obligations' => [
        'max_pages_per_fetch' => (int) env('STATUTORY_COVERAGE_SPOKE_MAX_PAGES', 50),
        'max_records_per_fetch' => (int) env('STATUTORY_COVERAGE_SPOKE_MAX_RECORDS', 50000),
    ],

    'watchdog' => [
        'alert_key_prefix' => 'statutory_coverage',
    ],

];
