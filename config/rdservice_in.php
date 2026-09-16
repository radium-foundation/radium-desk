<?php

return [
    /*
    | Desk → rdservice.in wallet refund credit. Uses the rdservice_in spoke
    | token/base URL from config/order_lookup.php. Default off.
    */
    'wallet_refund_credit_enabled' => filter_var(
        env('RDSERVICE_IN_WALLET_REFUND_CREDIT_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
