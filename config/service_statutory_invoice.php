<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service statutory invoice mint retry
    |--------------------------------------------------------------------------
    |
    | Workflow triggers (reference assign, case close, customer-waiting auto-close)
    | attempt immediate mint via StatutoryInvoiceService. On retryable failure the
    | mint is enqueued to outbox_events (statutory.invoice.service_mint).
    |
    */
    'mint_retry' => [
        'enabled' => filter_var(env('SERVICE_STATUTORY_INVOICE_MINT_RETRY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation safety net
    |--------------------------------------------------------------------------
    |
    | Periodically scans workflow-completed online service commerce orders that
    | are mint-eligible but still lack a statutory invoice, then attempts mint
    | through the same idempotent issuance coordinator.
    |
    */
    'reconciliation' => [
        'enabled' => filter_var(env('SERVICE_STATUTORY_INVOICE_RECONCILIATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'schedule_interval_minutes' => max(5, (int) env('SERVICE_STATUTORY_INVOICE_RECONCILIATION_INTERVAL_MINUTES', 15)),
        // Maximum mint attempts per scheduler run (not a row-scan cap).
        'batch_limit' => max(1, (int) env('SERVICE_STATUTORY_INVOICE_RECONCILIATION_BATCH_LIMIT', 100)),
        // Safety cap on candidate rows scanned when the uninvoiced backlog is large.
        'max_scan_per_run' => max(100, (int) env('SERVICE_STATUTORY_INVOICE_RECONCILIATION_MAX_SCAN', 10_000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | GST mismatch statutory-invoice exception workflow (360)
    |--------------------------------------------------------------------------
    */
    'gst_mismatch' => [
        'enabled' => filter_var(env('SERVICE_STATUTORY_INVOICE_GST_MISMATCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'response_hours' => max(1, (int) env('SERVICE_STATUTORY_INVOICE_GST_MISMATCH_RESPONSE_HOURS', 72)),
        'customer_email_enabled' => filter_var(env('SERVICE_STATUTORY_INVOICE_GST_MISMATCH_EMAIL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'schedule_interval_minutes' => max(5, (int) env('SERVICE_STATUTORY_INVOICE_GST_MISMATCH_INTERVAL_MINUTES', 15)),
    ],

];
