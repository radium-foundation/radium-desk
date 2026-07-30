<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contribution Engine (Milestone 4+)
    |--------------------------------------------------------------------------
    |
    | When disabled (default), ContributionEngine returns a disabled evaluation
    | and publishes no events. Attendance behaviour is unchanged.
    |
    | Thresholds live only in this file (calibration without code changes).
    |
    */
    'enabled' => (bool) env('WORKFORCE_CONTRIBUTION_ENABLED', false),

    'default_pack' => 'support_agent',

    'calibration' => [
        'score' => [
            'high_average' => (float) env('WORKFORCE_CONTRIBUTION_SCORE_HIGH', 1.5),
            'normal_average' => (float) env('WORKFORCE_CONTRIBUTION_SCORE_NORMAL', 1.0),
        ],
    ],

    /*
    | Map Spatie role slugs → pack id. First matching role wins.
    */
    'role_pack_map' => [
        'operations_admin' => 'manager',
        'admin' => 'manager',
        'superadmin' => 'manager',
        'agent' => 'support_agent',
        'support_specialist' => 'support_agent',
        'customer_coordinator' => 'support_agent',
        'escalation_specialist' => 'support_agent',
        'hardware_team' => 'support_agent',
        // Future: map sales roles → sales_agent
    ],

    /*
    | Pack definitions — strategy: any_of | all_of | score
    */
    'packs' => [
        'support_agent' => [
            'label' => 'Support Agent',
            'strategy' => 'any_of',
            'signals' => [
                'active_duration' => [
                    'enabled' => true,
                    'normal' => 1800,
                    'high' => 14400,
                ],
                'cases_handled' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'cases_resolved' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 3,
                ],
                'cases_closed' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 3,
                ],
                'communications' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'email' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'whatsapp' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'calls' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 4,
                ],
                'status_updates' => ['enabled' => false],
                'remarks' => ['enabled' => false],
                'orders_activated' => ['enabled' => false],
                'sales' => ['enabled' => false, 'reserved' => true],
                'manual_adjustment' => ['enabled' => false, 'reserved' => true],
            ],
        ],
        'sales_agent' => [
            'label' => 'Sales Agent',
            'strategy' => 'any_of',
            'signals' => [
                'active_duration' => [
                    'enabled' => true,
                    'normal' => 1800,
                    'high' => 14400,
                ],
                'orders_activated' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'communications' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 8,
                ],
                'whatsapp' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'calls' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 5,
                ],
                'cases_handled' => ['enabled' => false],
                'cases_resolved' => ['enabled' => false],
                'cases_closed' => ['enabled' => false],
                'email' => ['enabled' => false],
                'status_updates' => ['enabled' => false],
                'remarks' => ['enabled' => false],
                'sales' => ['enabled' => false, 'reserved' => true],
                'manual_adjustment' => ['enabled' => false, 'reserved' => true],
            ],
        ],
        'manager' => [
            'label' => 'Manager',
            'strategy' => 'any_of',
            'signals' => [
                'active_duration' => [
                    'enabled' => true,
                    'normal' => 3600,
                    'high' => 21600,
                ],
                'status_updates' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 10,
                ],
                'remarks' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 8,
                ],
                'cases_handled' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 3,
                ],
                'calls' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 3,
                ],
                'cases_closed' => [
                    'enabled' => true,
                    'normal' => 1,
                    'high' => 2,
                ],
                'cases_resolved' => ['enabled' => false],
                'communications' => ['enabled' => false],
                'email' => ['enabled' => false],
                'whatsapp' => ['enabled' => false],
                'orders_activated' => ['enabled' => false],
                'sales' => ['enabled' => false, 'reserved' => true],
                'manual_adjustment' => ['enabled' => false, 'reserved' => true],
            ],
        ],
    ],

];
