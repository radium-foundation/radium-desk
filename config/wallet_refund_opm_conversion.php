<?php

return [
    'sites' => [
        'radiumbox.com' => [
            'enabled' => filter_var(env('WALLET_REFUND_OPM_CONVERSION_RADIUMBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'rdservice.in' => [
            'enabled' => filter_var(env('WALLET_REFUND_OPM_CONVERSION_RDSERVICE_IN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'rdservice.net' => [
            'enabled' => filter_var(env('WALLET_REFUND_OPM_CONVERSION_RDSERVICE_NET_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    'cashfree' => [
        'successful_statuses' => ['SUCCESS', 'success'],
    ],
];
