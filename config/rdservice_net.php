<?php

return [
    /*
    | Desk → rdservice.net Central Wallet refund credit. Uses the rdservice_net
    | spoke token/base URL from config/order_lookup.php. Default off.
    */
    'wallet_refund_credit_enabled' => filter_var(
        env('RDSERVICE_NET_WALLET_REFUND_CREDIT_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
