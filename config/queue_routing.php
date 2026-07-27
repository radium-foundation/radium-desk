<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Named Queue Routing
    |--------------------------------------------------------------------------
    |
    | Logical queue names used for Hostinger-safe priority draining.
    | Worker listens in worker_order (leftmost = highest priority).
    |
    */

    'queues' => [
        'critical' => env('QUEUE_NAME_CRITICAL', 'critical'),
        'notifications' => env('QUEUE_NAME_NOTIFICATIONS', 'notifications'),
        'maintenance' => env('QUEUE_NAME_MAINTENANCE', 'maintenance'),
        'default' => env('QUEUE_NAME_DEFAULT', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Drain Order
    |--------------------------------------------------------------------------
    |
    | Keys into queues above. Must remain:
    | critical → notifications → default → maintenance
    |
    */

    'worker_order' => [
        'critical',
        'notifications',
        'default',
        'maintenance',
    ],

];
