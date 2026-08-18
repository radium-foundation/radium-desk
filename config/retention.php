<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database retention policies (days)
    |--------------------------------------------------------------------------
    |
    | Used by database:retention-inspect (read-only) and future prune commands.
    | Inspect only reports candidates; nothing is deleted until a separate
    | prune command is implemented and scheduled.
    |
    */

    'completed_outbox_days' => max(1, (int) env('RETENTION_COMPLETED_OUTBOX_DAYS', 14)),

    'webhook_logs_days' => max(1, (int) env('RETENTION_WEBHOOK_LOGS_DAYS', 90)),

    'notifications_days' => max(1, (int) env('RETENTION_NOTIFICATIONS_DAYS', 90)),

    'business_audit_days' => max(1, (int) env('RETENTION_BUSINESS_AUDIT_DAYS', 365)),

    'ignored_email_days' => max(1, (int) env('RETENTION_IGNORED_EMAIL_DAYS', 90)),

    /*
    | Expired cache rows (expiration < now) are immediate prune candidates.
    | No day-based grace period — Laravel already skips them on read.
    */
    'expired_cache_immediate' => filter_var(
        env('RETENTION_EXPIRED_CACHE_IMMEDIATE', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
