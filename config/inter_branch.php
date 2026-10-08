<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy POS inter-branch reconciliation
    |--------------------------------------------------------------------------
    |
    | Historical Delhi → Mumbai movements were incorrectly completed through
    | POS retail sale. Reconciliation links the existing statutory invoice and
    | sale to a new inter-branch transaction without minting another invoice.
    |
    */

    'legacy_reconciliation' => [
        'source_branch_codes' => [
            'DELHI-RETAIL',
        ],
        'require_irn' => true,
    ],

];
