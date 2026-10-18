<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Online return-case refund allocation execution
    |--------------------------------------------------------------------------
    |
    | When false (default), Finance may approve allocations but line execution
    | endpoints fail closed. Enable only after operational review.
    |
    */
    'online_refund_allocation' => [
        'execution_enabled' => filter_var(
            env('RETURN_EXCHANGE_ONLINE_REFUND_ALLOCATION_EXECUTION_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'opm_conversion_enabled' => filter_var(
            env('RETURN_EXCHANGE_ONLINE_REFUND_ALLOCATION_OPM_CONVERSION_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

];
