<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Host load watchdog (Phase 1 — alert only)
    |--------------------------------------------------------------------------
    |
    | Detects sustained abnormal host CPU load, identifies likely workload,
    | sends Telegram critical alerts, and records file-backed incidents.
    | Automatic remediation is explicitly disabled in Phase 1.
    |
    | Thresholds below are PROPOSED defaults from RadiumDesk-P-25-09-117.
    | Tune per host core count and observed baseline — do not treat as permanent.
    |
    */
    'enabled' => (bool) env('HOST_LOAD_WATCHDOG_ENABLED', false),

    'schedule_interval_minutes' => max(1, (int) env('HOST_LOAD_WATCHDOG_INTERVAL_MINUTES', 2)),

    'host_name' => env('HOST_LOAD_WATCHDOG_HOST_NAME', (string) gethostname()),

    'project_name' => env('HOST_LOAD_WATCHDOG_PROJECT_NAME', 'RadiumDesk'),

    'thresholds' => [
        'elevated_load1' => (float) env('HOST_LOAD_WATCHDOG_ELEVATED_LOAD1', 2.0),
        'elevated_consecutive_samples' => max(1, (int) env('HOST_LOAD_WATCHDOG_ELEVATED_SAMPLES', 3)),
        'critical_load1' => (float) env('HOST_LOAD_WATCHDOG_CRITICAL_LOAD1', 4.0),
        'critical_consecutive_samples' => max(1, (int) env('HOST_LOAD_WATCHDOG_CRITICAL_SAMPLES', 2)),
        'normal_load1' => (float) env('HOST_LOAD_WATCHDOG_NORMAL_LOAD1', 1.5),
    ],

    'cooldown_minutes' => max(1, (int) env('HOST_LOAD_WATCHDOG_COOLDOWN_MINUTES', 10)),

    'incident_retention_days' => max(1, (int) env('HOST_LOAD_WATCHDOG_RETENTION_DAYS', 30)),

    'sampler' => [
        'enabled' => (bool) env('HOST_LOAD_WATCHDOG_SAMPLER_ENABLED', true),
        'directory' => env('HOST_LOAD_WATCHDOG_SAMPLER_DIR', storage_path('logs/cpu-process-samples')),
    ],

];
