<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Serial Pattern Profile Model Aliases
    |--------------------------------------------------------------------------
    |
    | Maps production device_model / product_name variants to the canonical
    | serial_pattern_profiles keys. Used for IRA serial intelligence only.
    |
    */

    'vendor_prefixes' => [
        'MANTRA',
        'STARTEK',
        'MORPHO',
        'ACCESS',
    ],

    'aliases' => [
        'MIS 100' => [
            'Mantra MIS100',
            'MIS 100',
            'MIS100',
            'MIS100V2',
        ],
        'MFS 110' => [
            'Mantra MFS110',
            'MFS 110',
            'MFS110',
        ],
        'FM 220' => [
            'Startek FM220',
            'FM220',
            'FM220U',
            'Access FM220',
            'Access FM220 L1',
            'Access FM 220 U',
        ],
        'MSO E3' => [
            'Morpho MSO1300 E3',
            'MSO1300 E3',
            'MSO E3',
        ],
    ],
];
