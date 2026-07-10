<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Model-Aware Serial Pattern Profiles
    |--------------------------------------------------------------------------
    |
    | Learned from production serial learning exports, agent corrections,
    | customer-entered wrong serials, and verified training samples.
    |
    | These profiles inform SerialInsight confidence and IRA guidance only.
    | Deterministic validation gates remain in product validator classes.
    |
    */

    'MSO E3' => [
        'valid_format_description' => 'four digits, capital I, then six digits',
        'valid_pattern' => '/^\d{4}I\d{6}$/',
        'failure_guidance' => 'Ask customer for the device back-side label photo or an internal serial screenshot from device settings.',
        'verified_valid' => [
            '2402I013577', '2441I041803', '2506I022251', '2506I023436', '2416I013262',
            '2404I009426', '2439I031026', '2435I004993', '2437I017521', '2507I005871',
            '2406I002745', '2441I034744', '2506I022940', '2351I002764', '2507I002991',
            '2405I002878', '2423I016089', '2541I013227', '2424I023017', '2425I022416',
            '2406I010321', '2526I057948',
        ],
        'verified_wrong' => [
            '171O1367737', 'MSO1300 E3-L1 RD', 'MSO1300 e3', '2208I013400',
            'ESIAP6641', 'MPH-SE005C', 'IIMPROUB2035',
        ],
        'wrong_patterns' => [
            [
                'regex' => '/MSO|E3-L|MPH-SE|ESIAP|IIMPROU/i',
                'reason' => 'model or part identifier entered as serial',
                'confidence' => 'high',
            ],
            [
                'regex' => '/^\d{4}O\d{6}$/',
                'reason' => 'O vs I confusion in serial',
                'confidence' => 'high',
            ],
        ],
    ],

    'FM 220' => [
        'valid_format_description' => 'M followed by a nine-character alphanumeric sequence with model code 22–26 in positions 2–3',
        'valid_pattern' => '/^M(?:2[2-6]|P)\d{7}$/i',
        'failure_guidance' => 'Ask customer for the FM 220 back-panel serial photo showing the M-prefix label.',
        'verified_valid' => [
            'M240327686', 'M240365655', 'M240261927', 'M240243707', 'M240261769',
            'M240327064', 'M240192099', 'M240381124', 'M240367367', 'M240306770',
            'M260779805', 'M250546898', 'P250546898', 'M220546898',
        ],
        'verified_wrong' => [
            'B47966880', 'B47C70263', 'N00106486', 'FM220U L1', 'M2506300030',
            'X002AQXA2p', '9009370', 'TC067262100185',
        ],
        'wrong_patterns' => [
            [
                'regex' => '/^FM\s*220|^FM220U|^ACCESS\s*FM220/i',
                'reason' => 'model name entered as serial',
                'confidence' => 'high',
            ],
            [
                'regex' => '/^X\d{3}[A-Z]{4}/i',
                'reason' => 'marketplace or reseller code entered as serial',
                'confidence' => 'high',
            ],
            [
                'regex' => '/^\d{7,9}$/',
                'reason' => 'numeric-only value without FM 220 M-prefix format',
                'confidence' => 'high',
            ],
        ],
    ],

    'MFS 110' => [
        'valid_format_description' => 'seven digits starting with 6–9, or eight digits starting with 1',
        'valid_pattern' => '/^(?:[6-9]\d{6}|1\d{7})$/',
        'failure_guidance' => 'Ask customer for the MFS 110 device back-side photo or internal serial screenshot.',
        'verified_valid' => [
            '6419897', '6246404', '7438383', '8232191', '8910298', '8880867',
            '8084978', '8912965', '10452948', '10402754', '7881953', '6881953',
        ],
        'verified_wrong' => [
            'MFS110', 'MANTRA MFS110', 'FPSPL1141XX', 'MFS110-FPSPL1141XX',
            'P/N:FPSPL1141XX', '127.0.0.1:11100', '079-49068000', '54SAXXC5514586',
            '54SAXXC3089378', 'KAMAL', '5VDC/0.5A', 'P/N:FPSPL1141XX',
        ],
        'wrong_patterns' => [
            [
                'regex' => '/^MFS\s*110$|^MANTRA\s*MFS|^54SAXX|^FPSPL|^P\/N|^PFSPL/i',
                'reason' => 'model name or part number entered as serial',
                'confidence' => 'high',
            ],
            [
                'regex' => '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/',
                'reason' => 'URL or IP address entered as serial',
                'confidence' => 'high',
            ],
            [
                'regex' => '/^\d{3}-\d{8,}$/',
                'reason' => 'phone number entered as serial',
                'confidence' => 'high',
            ],
        ],
        'likely_other_model_serials' => [
            'MIS 100' => ['6300791', '3673434', '5969551', '8850830'],
        ],
    ],

    'MIS 100' => [
        'valid_format_description' => 'seven numeric digits, or eight digits starting with 1',
        'valid_pattern' => '/^(?:\d{7}|1\d{7})$/',
        'failure_guidance' => 'Ask customer for the MIS 100 device back-side photo or internal serial screenshot.',
        'verified_valid' => ['6300791', '3673434', '5969551', '8850830', '9655721'],
        'verified_wrong' => [
            'MIS100V2', '8011332', '6389437', '1CD8E86E46E0',
        ],
        'wrong_patterns' => [
            [
                'regex' => '/^MIS\s*100/i',
                'reason' => 'model name entered as serial',
                'confidence' => 'high',
            ],
            [
                'regex' => '/^[0-9A-F]{12}$/i',
                'reason' => 'MAC or address-style string entered as serial',
                'confidence' => 'high',
            ],
        ],
        'likely_other_model_serials' => [
            'MFS 110' => ['8011332', '6389437'],
        ],
    ],
];
