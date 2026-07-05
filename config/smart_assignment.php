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

];
