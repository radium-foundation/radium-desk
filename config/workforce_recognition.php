<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Work Recognition (independent of Attendance / OT)
    |--------------------------------------------------------------------------
    |
    | When disabled, scan/nav/UI no-op. Attendance is never written.
    |
    */
    'enabled' => (bool) env('WORKFORCE_RECOGNITION_ENABLED', false),

    'snapshot_version' => 1,

    /*
    | Map Spatie role slugs → recognition department pack. First match wins.
    */
    'role_pack_map' => [
        'operations_admin' => 'management',
        'admin' => 'management',
        'superadmin' => 'management',
        'agent' => 'support',
        'support_specialist' => 'support',
        'customer_coordinator' => 'support',
        'escalation_specialist' => 'support',
        'hardware_team' => 'operations',
        'employee' => 'operations',
    ],

    'default_pack' => 'support',

    /*
    | Department packs — weights are relative; login/active_duration kept low.
    | thresholds map pack score bands → RecognitionRecommendation values.
    */
    'packs' => [
        'support' => [
            'label' => 'Support',
            'signal_weights' => [
                'cases_resolved' => 3.0,
                'cases_closed' => 3.0,
                'cases_handled' => 2.0,
                'communications' => 1.5,
                'email' => 1.0,
                'whatsapp' => 1.0,
                'calls' => 1.5,
                'remarks' => 0.5,
                'status_updates' => 0.5,
                'active_duration' => 0.1,
                'orders_activated' => 0.5,
            ],
            'require_business_signal_above' => 'appreciation',
            'bands' => [
                ['min' => 0, 'max' => 0.49, 'recommendation' => 'no_benefit'],
                ['min' => 0.5, 'max' => 1.49, 'recommendation' => 'appreciation'],
                ['min' => 1.5, 'max' => 2.99, 'recommendation' => 'half_extra'],
                ['min' => 3.0, 'max' => 4.99, 'recommendation' => 'full_extra'],
                ['min' => 5.0, 'max' => null, 'recommendation' => 'bonus'],
            ],
        ],
        'operations' => [
            'label' => 'Operations',
            'signal_weights' => [
                'orders_activated' => 3.0,
                'cases_handled' => 1.0,
                'cases_resolved' => 1.0,
                'communications' => 1.0,
                'calls' => 1.0,
                'email' => 0.5,
                'whatsapp' => 0.5,
                'active_duration' => 0.1,
                'remarks' => 0.5,
            ],
            'require_business_signal_above' => 'appreciation',
            'bands' => [
                ['min' => 0, 'max' => 0.49, 'recommendation' => 'no_benefit'],
                ['min' => 0.5, 'max' => 1.49, 'recommendation' => 'appreciation'],
                ['min' => 1.5, 'max' => 2.99, 'recommendation' => 'half_extra'],
                ['min' => 3.0, 'max' => 4.99, 'recommendation' => 'full_extra'],
                ['min' => 5.0, 'max' => null, 'recommendation' => 'comp_off'],
            ],
        ],
        'accounts' => [
            'label' => 'Accounts',
            'signal_weights' => [
                'orders_activated' => 2.0,
                'email' => 2.0,
                'remarks' => 1.0,
                'active_duration' => 0.1,
            ],
            'require_business_signal_above' => 'appreciation',
            'bands' => [
                ['min' => 0, 'max' => 0.49, 'recommendation' => 'no_benefit'],
                ['min' => 0.5, 'max' => 1.99, 'recommendation' => 'appreciation'],
                ['min' => 2.0, 'max' => 3.99, 'recommendation' => 'half_extra'],
                ['min' => 4.0, 'max' => null, 'recommendation' => 'full_extra'],
            ],
        ],
        'management' => [
            'label' => 'Management',
            'signal_weights' => [
                'cases_handled' => 1.0,
                'orders_activated' => 1.0,
                'communications' => 1.0,
                'status_updates' => 1.0,
                'remarks' => 1.0,
                'active_duration' => 0.1,
            ],
            'require_business_signal_above' => 'appreciation',
            'bands' => [
                ['min' => 0, 'max' => 0.49, 'recommendation' => 'no_benefit'],
                ['min' => 0.5, 'max' => 2.99, 'recommendation' => 'appreciation'],
                ['min' => 3.0, 'max' => null, 'recommendation' => 'full_extra'],
            ],
        ],
        'warehouse' => [
            'label' => 'Warehouse',
            'signal_weights' => [
                'orders_activated' => 3.0,
                'active_duration' => 0.1,
                'remarks' => 0.5,
            ],
            'require_business_signal_above' => 'appreciation',
            'bands' => [
                ['min' => 0, 'max' => 0.49, 'recommendation' => 'no_benefit'],
                ['min' => 0.5, 'max' => 1.49, 'recommendation' => 'appreciation'],
                ['min' => 1.5, 'max' => 2.99, 'recommendation' => 'half_extra'],
                ['min' => 3.0, 'max' => null, 'recommendation' => 'full_extra'],
            ],
        ],
    ],

    /*
    | Signal ids treated as "business" (not login duration) for the
    | require_business_signal_above gate.
    */
    'business_signal_ids' => [
        'cases_handled',
        'cases_resolved',
        'cases_closed',
        'communications',
        'email',
        'whatsapp',
        'calls',
        'status_updates',
        'remarks',
        'orders_activated',
    ],
];
