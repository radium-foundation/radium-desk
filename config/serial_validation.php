<?php

return [
    /*
    |--------------------------------------------------------------------------
    | IRA Serial Validation — Supported Products
    |--------------------------------------------------------------------------
    |
    | Product-specific rules are implemented in dedicated validator classes
    | under App\Services\SerialValidation\Validators and orchestrated by
    | SerialValidationService.
    |
    */
    'supported_products' => [
        'MFS 110',   // 7 digits (first 6-9) or 8 digits (starts with 1), numeric only
        'MIS 100',   // 7 digits (any) or 8 digits (starts with 1), numeric only
        'MSO E3',    // exactly 11 chars; positions 1-4 and 6-11 numeric; position 5 is I; rejects 17-22 prefixes
        'FM 220',    // exactly 10 alphanumeric; starts M/P; chars 2-3 are 22-25
        'PB 1000',   // exactly 12 alphanumeric; starts LN or LU
        'MARC 11',   // 7 or 10 digits; starts with 7 or 8, numeric only
    ],
];
