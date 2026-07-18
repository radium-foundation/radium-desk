<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound Email Intake (Phase 1)
    |--------------------------------------------------------------------------
    |
    | When enabled is false (default), ingest is a no-op so existing behaviour
    | is unchanged. Phase 2 plugs Gmail History sync into the same ingest path.
    |
    */

    'enabled' => filter_var(env('INBOUND_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'preview_max_chars' => (int) env('INBOUND_EMAIL_PREVIEW_MAX_CHARS', 280),

    /*
    |--------------------------------------------------------------------------
    | Mailbox → logical channel label
    |--------------------------------------------------------------------------
    */

    'mailboxes' => [
        'support@radiumbox.com' => 'support',
        'service@radiumbox.com' => 'service',
        'refund@radiumbox.com' => 'refund',
        'sales@radiumbox.com' => 'sales',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked senders / domains
    |--------------------------------------------------------------------------
    */

    'blocked_senders' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INBOUND_EMAIL_BLOCKED_SENDERS', '')),
    ))),

    'blocked_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INBOUND_EMAIL_BLOCKED_DOMAINS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | System / bounce / auto-responder patterns
    |--------------------------------------------------------------------------
    */

    'system_sender_patterns' => [
        'mailer-daemon@',
        'mail-daemon@',
        'postmaster@',
        'noreply@',
        'no-reply@',
        'donotreply@',
        'do-not-reply@',
        'bounce@',
        'bounces@',
    ],

    'system_from_names' => [
        'mail delivery subsystem',
        'mail delivery system',
        'mailer-daemon',
        'postmaster',
    ],

    'auto_responder_header_tokens' => [
        'auto-submitted',
        'x-autoreply',
        'x-autorespond',
        'x-auto-response-suppress',
        'precedence',
        'list-unsubscribe',
        'list-id',
    ],

    'ignore_subject_patterns' => [
        '/^out of office/i',
        '/^automatic reply/i',
        '/^auto[:\s-]*reply/i',
        '/^undeliverable/i',
        '/delivery status notification/i',
        '/mail delivery failed/i',
        '/failure notice/i',
        '/newsletter/i',
        '/unsubscribe/i',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gmail-style category / label ignores (when provider supplies labels)
    |--------------------------------------------------------------------------
    */

    'ignored_labels' => [
        'SPAM',
        'TRASH',
        'CATEGORY_PROMOTIONS',
        'CATEGORY_SOCIAL',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gmail live sync (Google Workspace)
    |--------------------------------------------------------------------------
    |
    | Uses a service account with domain-wide delegation to impersonate each
    | configured mailbox. On first enablement, only the current historyId is
    | stored — no historical messages are imported.
    |
    */

    'gmail' => [
        'enabled' => filter_var(env('INBOUND_EMAIL_GMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON', storage_path('app/google/service-account.json')),

        'subject' => env('GOOGLE_WORKSPACE_IMPERSONATED_USER'), // optional default; per-mailbox impersonation uses mailbox address

        'scopes' => [
            'https://www.googleapis.com/auth/gmail.readonly',
        ],

        'api_base_url' => rtrim(env('GMAIL_API_BASE_URL', 'https://gmail.googleapis.com'), '/'),

        'token_url' => env('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),

        'timeout_seconds' => (int) env('GMAIL_API_TIMEOUT_SECONDS', 20),

        'connect_timeout_seconds' => (int) env('GMAIL_API_CONNECT_TIMEOUT_SECONDS', 5),

        'max_results_per_page' => (int) env('GMAIL_HISTORY_MAX_RESULTS', 100),

        /*
         * Optional Gmail historyTypes filter (comma-separated). When null/empty, all
         * history event types are returned — required for alias-routed Workspace mail
         * that may surface under messages[] instead of messagesAdded[].
         */
        'history_types' => ($types = trim((string) env('GMAIL_HISTORY_TYPES', ''))) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $types))))
            : null,

        'http_retry_times' => (int) env('GMAIL_HTTP_RETRY_TIMES', 3),

        'http_retry_sleep_ms' => (int) env('GMAIL_HTTP_RETRY_SLEEP_MS', 500),

        'schedule_interval_minutes' => (int) env('INBOUND_EMAIL_GMAIL_SYNC_INTERVAL_MINUTES', 1),

        /*
         * Mailboxes to sync. Defaults to keys of inbound_email.mailboxes when empty.
         * Comma-separated env override: support@radiumbox.com,service@radiumbox.com
         */
        'sync_mailboxes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INBOUND_EMAIL_GMAIL_MAILBOXES', '')),
        ))),
    ],
];
