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
        'assignee_email' => env('SERVICE_CASE_DAY_ADMIN_EMAIL', 'avinash.jha@radium.example'),
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
        'assignee_email' => env('SERVICE_CASE_AFTER_HOURS_ADMIN_EMAIL', 'shipra.kumari@radium.example'),
    ],

];
