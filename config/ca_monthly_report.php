<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Synchronous export line threshold
    |--------------------------------------------------------------------------
    |
    | Exports with more than this many statutory invoice lines are queued for
    | background generation. Production v4.0.104 OOM'd at 128MB for a full
    | September 2026 month (~4.5k invoices); a single day (~388 lines) succeeded.
    | Benchmarked locally on sqlite (see CaMonthlyReportExportBenchmarkTest):
    | 500 lines → ~262ms XLSX, ~105MB peak; 5,000 lines → ~2.6s, ~107MB peak.
    | Default 500 keeps typical single-day volumes synchronous while full-month
    | ranges queue asynchronously.
    |
    */

    'sync_max_lines' => (int) env('CA_MONTHLY_REPORT_SYNC_MAX_LINES', 500),

    /*
    |--------------------------------------------------------------------------
    | Background export queue
    |--------------------------------------------------------------------------
    |
    | Uses the existing maintenance queue (lowest priority in Supervisor:
    | critical → notifications → default → maintenance). No production
    | Supervisor change is required.
    |
    */

    'export_queue' => env('CA_MONTHLY_REPORT_EXPORT_QUEUE', 'maintenance'),

    /*
    |--------------------------------------------------------------------------
    | Export artifact retention
    |--------------------------------------------------------------------------
    */

    'retention_hours' => (int) env('CA_MONTHLY_REPORT_RETENTION_HOURS', 72),

    'storage_disk' => env('CA_MONTHLY_REPORT_STORAGE_DISK', 'local'),

    'storage_directory' => 'ca-monthly-report-exports',

    /*
    |--------------------------------------------------------------------------
    | Email attachment limit
    |--------------------------------------------------------------------------
    |
    | When the generated artifact exceeds this size, email delivery uses a
    | signed download link instead of attaching the file. Default 8 MiB is
    | conservative for typical SMTP limits without hard-coding a provider value.
    |
    */

    'max_email_attachment_bytes' => (int) env('CA_MONTHLY_REPORT_MAX_EMAIL_ATTACHMENT_BYTES', 8 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Idempotency window (minutes)
    |--------------------------------------------------------------------------
    |
    | Duplicate export requests with the same user, date range, and format
    | within this window reuse the existing non-failed export record.
    |
    */

    'idempotency_window_minutes' => (int) env('CA_MONTHLY_REPORT_IDEMPOTENCY_WINDOW_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Invoice chunk size for bounded-memory generation
    |--------------------------------------------------------------------------
    */

    'invoice_chunk_size' => (int) env('CA_MONTHLY_REPORT_INVOICE_CHUNK_SIZE', 25),

    /*
    |--------------------------------------------------------------------------
    | Super Admin download history window
    |--------------------------------------------------------------------------
    |
    | Operational metadata only. No retention cleanup job is scheduled; exports
    | remain until pruned by PruneCaMonthlyReportExportsCommand per retention_hours.
    |
    */

    'download_history_days' => (int) env('CA_MONTHLY_REPORT_DOWNLOAD_HISTORY_DAYS', 90),

    'download_history_per_page' => (int) env('CA_MONTHLY_REPORT_DOWNLOAD_HISTORY_PER_PAGE', 15),

];
