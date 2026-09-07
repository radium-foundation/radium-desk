<?php

return [

    /*
    | When false (default), Cashfree OrderPaid does not write hardware payment
    | evidence. P2 keeps this off so live RDE* webhooks, including the seven
    | frozen orders, are not processed by the fulfilment workflow.
    */
    'correlate_cashfree' => false,

];
