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

    /*
    | Chunk sizes for retention prune commands (small batches for live production).
    | Future scheduler entries should use ->withoutOverlapping().
    */
    'cache_prune_batch_size' => max(1, (int) env('RETENTION_CACHE_PRUNE_BATCH_SIZE', 500)),

    'outbox_prune_batch_size' => max(1, (int) env('RETENTION_OUTBOX_PRUNE_BATCH_SIZE', 1000)),

    /*
    | Historical Gmail baseline noise (received_at cutoff — not created_at).
    | Used by database:retention-inspect-historical-gmail-noise (read-only today).
    | Does not alter ignored_email_days or other ongoing retention policies.
    */
    'historical_gmail_noise' => [
        'received_at_cutoff' => env('RETENTION_HISTORICAL_GMAIL_NOISE_RECEIVED_CUTOFF', '2026-07-01 00:00:00'),
        'excluded_message_ids' => [244287],
        'ignore_reasons' => [
            'promotions',
            'social',
            'spam',
            'trash',
            'newsletter_or_marketing',
            'known_system_email',
            'auto_responder',
            'bounce_or_delivery_subsystem',
            'own_outbound',
        ],
        'sample_id_limit' => max(1, (int) env('RETENTION_HISTORICAL_GMAIL_NOISE_SAMPLE_ID_LIMIT', 10)),
    ],

];
