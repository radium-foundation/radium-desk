<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cron Queue Worker
    |--------------------------------------------------------------------------
    |
    | When enabled, the scheduler runs a short-lived queue worker every minute.
    | Intended for shared hosting (e.g. Hostinger) without a persistent worker.
    |
    */

    'queue_cron_worker_enabled' => filter_var(
        env('QUEUE_CRON_WORKER_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Metrics Collection
    |--------------------------------------------------------------------------
    |
    | When enabled, the scheduler captures queue and integration health metrics.
    |
    */

    'metrics_enabled' => filter_var(
        env('INFRASTRUCTURE_METRICS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
