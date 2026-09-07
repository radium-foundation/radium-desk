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

];
