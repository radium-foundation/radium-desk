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

    /*
    | Desk → rdservice.net Central Wallet refund reversal (debit). Requires
    | rdservice.net to deploy POST /api/integrations/v1/wallet-refund-reversals.
    | Default off — fail-closed until the cross-project gate ships.
    */
    'wallet_refund_reversal_enabled' => filter_var(
        env('RDSERVICE_NET_WALLET_REFUND_REVERSAL_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
