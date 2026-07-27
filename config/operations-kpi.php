<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Activation session clustering
    |--------------------------------------------------------------------------
    |
    | Consecutive service_reference.assigned audits for the same user and
    | transaction_id within this gap are treated as one activation session.
    |
    */
    'activation_session_gap_seconds' => (int) env('OPERATIONS_KPI_ACTIVATION_SESSION_GAP_SECONDS', 2),

    'support' => [
        'effort_events' => [
            'status_updates' => ['service_case.status_changed'],
            'remarks' => ['created', 'deleted'],
            'whatsapp' => ['whatsapp.template_sent'],
            'emails' => [
                'notification.dispatched',
                'communication_action.lifecycle',
            ],
        ],
    ],

    'activation' => [
        'orders_activated_event' => 'service_reference.assigned',
        'failed_activation_event' => 'transaction.assignment_blocked',
        'driver_guide_event' => 'service_reference.driver_guide_sent',
    ],
];
