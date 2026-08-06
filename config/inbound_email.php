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

    /*
    |--------------------------------------------------------------------------
    | Auto-create Service Cases from inbound email (P[04-08]-004)
    |--------------------------------------------------------------------------
    |
    | When false (default), the processor keeps Historical / NeedsReview behaviour.
    | When true, customer-facing actionable mail creates or links a Service Case.
    | Internal operational classifications (Finance / HR / Vendor) never auto-create.
    |
    */

    'auto_create_service_case' => filter_var(
        env('INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Smart routing (Phase 1.3)
    |--------------------------------------------------------------------------
    |
    | Rule-based routing for new actionable email. When enabled, unmatched mail
    | is classified and routed to Support / Sales / Refund teams or Needs Human.
    | Existing active/closed case paths (Phase 1.1) are unchanged.
    |
    */

    'smart_routing_enabled' => filter_var(
        env('INBOUND_EMAIL_SMART_ROUTING_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'routing' => [
        'sales' => [
            'mailbox_channels' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_SALES_MAILBOX_CHANNELS', 'sales')),
            ))),
            'recipient_addresses' => array_values(array_filter(array_map(
                static fn (string $email): string => strtolower(trim($email)),
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_SALES_RECIPIENTS', '')),
            ))),
            'subject_keywords' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_SALES_SUBJECT_KEYWORDS', '')),
            ))),
            'from_aliases' => array_values(array_filter(array_map(
                static fn (string $email): string => strtolower(trim($email)),
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_SALES_FROM_ALIASES', '')),
            ))),
        ],
        'refund' => [
            'mailbox_channels' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_REFUND_MAILBOX_CHANNELS', 'refund')),
            ))),
            'subject_keywords' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_REFUND_SUBJECT_KEYWORDS', '')),
            ))),
        ],
        'support' => [
            'mailbox_channels' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_SUPPORT_MAILBOX_CHANNELS', 'support,service')),
            ))),
            'subject_keywords' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INBOUND_EMAIL_ROUTING_SUPPORT_SUBJECT_KEYWORDS', '')),
            ))),
        ],
    ],

    'assignment_settings' => [
        'refund_team_user_ids' => 'assignment.inbound_email_refund_team_user_ids',
        'sales_round_robin_user_ids' => 'assignment.inbound_email_sales_round_robin_user_ids',
        'sales_round_robin_cursor' => 'assignment.inbound_email_sales_round_robin_last_user_id',
        'refund_round_robin_cursor' => 'assignment.inbound_email_refund_round_robin_last_user_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority phrase detection (Dashboard V2 + attention categorization)
    |--------------------------------------------------------------------------
    |
    | Comma-separated phrases in INBOUND_EMAIL_PRIORITY_PHRASES. No defaults in
    | code — configure per environment.
    |
    */

    'priority_phrases' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INBOUND_EMAIL_PRIORITY_PHRASES', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Email Intake KPI widget cache
    |--------------------------------------------------------------------------
    |
    | Short TTL for Needs Attention / ignored hover aggregates. No Gmail API.
    |
    */

    'dashboard_widget_cache_seconds' => max(
        30,
        min(60, (int) env('INBOUND_EMAIL_DASHBOARD_WIDGET_CACHE_SECONDS', 45)),
    ),

    'preview_max_chars' => (int) env('INBOUND_EMAIL_PREVIEW_MAX_CHARS', 500),

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

        /*
         * gmail.send is required for Customer 360 operational reply (Phase 1).
         * Workspace domain-wide delegation must grant the same scopes before enabling reply.
         */
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
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
         * Hard ceiling for a single artisan sync invocation (CLI set_time_limit).
         * Keep below the schedule withoutOverlapping(10) mutex window (10 minutes).
         */
        'sync_timeout_seconds' => (int) env('INBOUND_EMAIL_GMAIL_SYNC_TIMEOUT_SECONDS', 540),

        /*
         * Mailboxes to sync. Defaults to keys of inbound_email.mailboxes when empty.
         * Comma-separated env override: support@radiumbox.com,service@radiumbox.com
         */
        'sync_mailboxes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INBOUND_EMAIL_GMAIL_MAILBOXES', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational reply (Customer 360 → Gmail API send)
    |--------------------------------------------------------------------------
    |
    | Not an inbox. Agents reply to a linked incoming message from Customer 360.
    | Default: disabled. Phase 1 rollout: support@ mailbox + email.reply permission.
    |
    */

    'reply' => [
        'enabled' => filter_var(env('INBOUND_EMAIL_REPLY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'mailboxes' => ($mailboxes = trim((string) env('INBOUND_EMAIL_REPLY_MAILBOXES', 'support@radiumbox.com'))) !== ''
            ? array_values(array_filter(array_map(
                static fn (string $email): string => strtolower(trim($email)),
                explode(',', $mailboxes),
            )))
            : ['support@radiumbox.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Closed-case reopen routing
    |--------------------------------------------------------------------------
    |
    | Refund-completed reopen assigns to the Refund Desk owner (default:
    | Shubhanshi). Successful-service reopen restores the last owner.
    | Own-outbound echoes never reopen (see IncomingEmailIngestService).
    |
    */

    'reopen' => [
        'refund_desk_user_email' => strtolower(trim((string) env(
            'INBOUND_EMAIL_REFUND_DESK_USER_EMAIL',
            env('SERVICE_CASE_ESCALATION_LEVEL_1_EMAIL', 'shubhanshi@radiumbox.com'),
        ))),
    ],
];
