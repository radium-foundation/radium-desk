<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Smart Assignment
    |--------------------------------------------------------------------------
    |
    | Intelligent case assignment based on availability, workload, and recent
    | activity. Triggered when customer actions create schedulable work.
    |
    */

    'enabled' => env('SMART_ASSIGNMENT_ENABLED', true),

    'activity_lookback_hours' => (int) env('SMART_ASSIGNMENT_ACTIVITY_LOOKBACK_HOURS', 2),

    /*
    |--------------------------------------------------------------------------
    | Deferred Smart Assignment
    |--------------------------------------------------------------------------
    |
    | When booking finds no eligible engineer, cases are marked pending and
    | retried in batches when engineers become eligible (or via scheduler).
    |
    */

    'deferred' => [
        'enabled' => env('SMART_ASSIGNMENT_DEFERRED_ENABLED', true),
        'batch_size' => (int) env('SMART_ASSIGNMENT_DEFERRED_BATCH_SIZE', 5),
        'schedule_interval_minutes' => (int) env('SMART_ASSIGNMENT_DEFERRED_SCHEDULE_INTERVAL_MINUTES', 5),
    ],

];

