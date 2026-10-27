<?php

return [

    /*
    | IND-series reduced copy from stored RadiumBox order data (hardware RDE* only).
    | Default off until written Finance/CA approval is recorded. Not a new tax invoice.
    */
    'ind_reduced_copy' => [
        'enabled' => filter_var(env('HISTORICAL_INVOICE_IND_REDUCED_COPY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
