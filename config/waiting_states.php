<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Customer Waiting Lifecycle Deployment
    |--------------------------------------------------------------------------
    |
    | Active waiting states that started before this timestamp are treated as
    | legacy when running the one-time customer waiting cleanup command.
    |
    */
    'lifecycle_deployment_at' => env('CUSTOMER_WAITING_LIFECYCLE_DEPLOYED_AT', '2026-07-07 00:00:00'),

    /*
    |--------------------------------------------------------------------------
    | Waiting Reasons
    |--------------------------------------------------------------------------
    |
    | Configurable waiting reasons attached to incidents. Each reason may define
    | a default reminder policy key and whether SLA should pause while active.
    | Reminder scheduling reads policy keys in a future phase.
    |
    */
    'reasons' => [
        'serial_number' => [
            'label' => 'Serial Number',
            'default_reminder_policy_key' => 'customer_waiting_default',
            'pause_sla' => true,
        ],
        'payment' => [
            'label' => 'Payment',
            'default_reminder_policy_key' => 'payment_default',
            'pause_sla' => true,
        ],
        'invoice' => [
            'label' => 'Invoice',
            'default_reminder_policy_key' => 'invoice_default',
            'pause_sla' => true,
        ],
        'customer_approval' => [
            'label' => 'Customer Approval',
            'default_reminder_policy_key' => 'customer_waiting_default',
            'pause_sla' => true,
        ],
        'photos' => [
            'label' => 'Photos',
            'default_reminder_policy_key' => 'customer_waiting_default',
            'pause_sla' => true,
        ],
        'device_pickup' => [
            'label' => 'Device Pickup',
            'default_reminder_policy_key' => 'device_pickup_default',
            'pause_sla' => true,
        ],
        'other' => [
            'label' => 'Other',
            'default_reminder_policy_key' => 'customer_waiting_default',
            'pause_sla' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminder Policies
    |--------------------------------------------------------------------------
    |
    | Named reminder policies referenced by waiting states. Scheduling logic
    | will resolve these keys without changing the waiting-state schema.
    |
    */
    'reminder_policies' => [
        'serial_number_default' => [
            'label' => 'Serial Number Default',
        ],
        'customer_waiting_default' => [
            'label' => 'Customer Waiting Default',
        ],
        'payment_default' => [
            'label' => 'Payment Default',
        ],
        'invoice_default' => [
            'label' => 'Invoice Default',
        ],
        'customer_approval_default' => [
            'label' => 'Customer Approval Default',
        ],
        'photos_default' => [
            'label' => 'Photos Default',
        ],
        'device_pickup_default' => [
            'label' => 'Device Pickup Default',
        ],
    ],
];
