<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Case Assignment Timezone
    |--------------------------------------------------------------------------
    |
    | Working-hours rules are evaluated in this timezone.
    |
    */

    'timezone' => env('SERVICE_CASE_ASSIGNMENT_TIMEZONE', env('APP_TIMEZONE', 'Asia/Kolkata')),

    /*
    |--------------------------------------------------------------------------
    | Day Shift Assignment
    |--------------------------------------------------------------------------
    |
    | Default owner between start and end (inclusive), e.g. 09:00–18:30.
    | Assignee is resolved by email — do not hardcode names in application code.
    |
    */

    'day_shift' => [
        'start' => env('SERVICE_CASE_DAY_SHIFT_START', '09:00'),
        'end' => env('SERVICE_CASE_DAY_SHIFT_END', '18:30'),
        'assignee_email' => env('SERVICE_CASE_DAY_ADMIN_EMAIL', 'avinash@radiumbox.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | After Hours Assignment
    |--------------------------------------------------------------------------
    |
    | Default owner outside day-shift hours (after end until start).
    |
    */

    'after_hours' => [
        'assignee_email' => env('SERVICE_CASE_AFTER_HOURS_ADMIN_EMAIL', 'shipra@radiumbox.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Admin Assignees
    |--------------------------------------------------------------------------
    |
    | Tried in order when the primary assignee is missing, inactive, or lacks
    | an admin role. Assignment only fails when no valid admin remains.
    |
    */

    'fallback_admins' => [
        env('SERVICE_CASE_FALLBACK_ADMIN_1', 'dileep@radiumbox.com'),
        env('SERVICE_CASE_FALLBACK_ADMIN_2', 'admin@radium.local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Round Robin Assignment (Phase 1)
    |--------------------------------------------------------------------------
    |
    | When enabled, new service cases are assigned to active support agents
    | using round robin. Set to false to restore shift-admin assignment.
    |
    */

    'round_robin_enabled' => env('SERVICE_CASE_ROUND_ROBIN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Automation Pending Grace Period (Phase 1.1)
    |--------------------------------------------------------------------------
    |
    | When enabled, new service cases enter an automation-pending grace period
    | before assignment. Set to false to restore immediate Phase 1 assignment.
    |
    */

    'automation_grace_period_enabled' => env('SERVICE_CASE_AUTOMATION_GRACE_PERIOD_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Hardware (RDE) Product Order Assignment
    |--------------------------------------------------------------------------
    |
    | Product orders whose order_id starts with the hardware prefix (RDE) are
    | routed to this assignee before round-robin or smart workload balancing.
    | Assignee is resolved by email — do not hardcode names in application code.
    |
    */

    'hardware_order' => [
        'assignee_email' => env('SERVICE_CASE_HARDWARE_ORDER_ASSIGNEE_EMAIL', 'sumit@radiumbox.com'),
    ],

];
