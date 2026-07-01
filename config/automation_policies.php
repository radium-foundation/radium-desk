<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automation Policies
    |--------------------------------------------------------------------------
    |
    | Named automation policies referenced by incident waiting states via
    | reminder_policy_key. Each policy defines scheduled actions relative to
    | the waiting state's started_at timestamp. Execution is handled in later
    | phases; this config is resolved by AutomationPolicyService only.
    |
    */
    'policies' => [
        'serial_number_default' => [
            'label' => 'Serial Number Default',
            'schedule' => [
                [
                    'day' => 0,
                    'actions' => [
                        [
                            'type' => 'whatsapp_template',
                            'key' => 'request_serial_number',
                        ],
                    ],
                ],
                [
                    'day' => 2,
                    'actions' => [
                        [
                            'type' => 'whatsapp_template',
                            'key' => 'request_serial_number_reminder',
                        ],
                    ],
                ],
                [
                    'day' => 5,
                    'actions' => [
                        [
                            'type' => 'whatsapp_template',
                            'key' => 'request_serial_number_reminder',
                        ],
                    ],
                ],
                [
                    'day' => 7,
                    'actions' => [
                        [
                            'type' => 'notify_team',
                            'key' => 'serial_number_escalation',
                        ],
                    ],
                ],
                [
                    'day' => 30,
                    'actions' => [
                        [
                            'type' => 'auto_close',
                            'key' => 'close_case_no_response',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
