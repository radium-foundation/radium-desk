<?php

use App\Enums\QueueWorkerMode;

$legacyCronWorkerEnabled = filter_var(
    env('QUEUE_CRON_WORKER_ENABLED', false),
    FILTER_VALIDATE_BOOLEAN,
);

$queueWorkerMode = QueueWorkerMode::resolve(
    env('QUEUE_WORKER_MODE'),
    $legacyCronWorkerEnabled,
);

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Mode
    |--------------------------------------------------------------------------
    |
    | How background jobs are drained in this environment:
    | disabled, scheduler, dedicated_cron, supervisor, horizon
    |
    | When QUEUE_WORKER_MODE is unset, QUEUE_CRON_WORKER_ENABLED=true maps to
    | scheduler; false maps to disabled.
    |
    */

    'queue_worker_mode' => $queueWorkerMode->value,

    /*
    |--------------------------------------------------------------------------
    | Cron Queue Worker (legacy)
    |--------------------------------------------------------------------------
    |
    | Derived from queue_worker_mode for backward compatibility. True only when
    | mode is scheduler (in-schedule queue:work). Health probes still read this
    | until migrated to queue_worker_mode.
    |
    */

    'queue_cron_worker_enabled' => $queueWorkerMode === QueueWorkerMode::Scheduler,

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
