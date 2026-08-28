<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Commerce module master switch
    |--------------------------------------------------------------------------
    |
    | When false, protected Commerce API endpoints refuse business operations.
    | The public health endpoint remains available for capability discovery.
    |
    */

    'enabled' => (bool) env('COMMERCE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Commerce API version label
    |--------------------------------------------------------------------------
    */

    'api_version' => '1',

    /*
    |--------------------------------------------------------------------------
    | Site authentication headers
    |--------------------------------------------------------------------------
    */

    'site_id_header' => 'X-Site-Id',

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (future phase)
    |--------------------------------------------------------------------------
    |
    | Attach Laravel throttle middleware to Commerce route groups when
    | Commerce-specific limits are defined. Not enabled in the skeleton phase.
    |
    */

    'rate_limiting' => [
        'enabled' => false,
    ],

];
