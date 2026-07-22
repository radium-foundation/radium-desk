<?php

return [
    'max_batch_size' => (int) env('REPAIR_MAX_BATCH_SIZE', 500),
    'default_limit' => (int) env('REPAIR_DEFAULT_LIMIT', 100),
    'lock_ttl_seconds' => (int) env('REPAIR_LOCK_TTL_SECONDS', 180),
    'checkpoint_every' => (int) env('REPAIR_CHECKPOINT_EVERY', 10),
    'export_path' => env('REPAIR_EXPORT_PATH', 'repairs'),
    'retention_days' => (int) env('REPAIR_RETENTION_DAYS', 90),
    'require_global_lock' => filter_var(env('REPAIR_REQUIRE_GLOBAL_LOCK', true), FILTER_VALIDATE_BOOLEAN),
];
