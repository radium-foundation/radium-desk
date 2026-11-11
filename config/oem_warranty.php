<?php

return [
    'session_ttl_seconds' => (int) env('OEM_WARRANTY_SESSION_TTL_SECONDS', 900),
    'extension_id' => env('OEM_WARRANTY_CHROME_EXTENSION_ID'),

    'desk_url_patterns' => [
        '/orders/',
    ],

    'allowed_desk_host_suffixes' => array_filter(array_map(
        'trim',
        explode(',', (string) env('OEM_WARRANTY_DESK_HOST_SUFFIXES', 'radiumdesk.test,localhost')),
    )),

    'providers' => [
        'mantratec' => [
            'portal_url' => 'https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx',
            'host_permissions' => [
                'https://servico.mantratecapp.com/*',
            ],
        ],
        'morpho' => [
            'portal_url' => 'https://rdserviceonline.com/pages/172iserialvalidatenumber',
            'host_permissions' => [
                'https://rdserviceonline.com/*',
            ],
        ],
    ],
];
