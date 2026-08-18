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
        'prune_batch_size' => max(1, (int) env('RETENTION_HISTORICAL_GMAIL_NOISE_PRUNE_BATCH_SIZE', 10000)),
    ],

    /*
    | audit_logs read-only inspection (database:retention-inspect-audit-logs).
    | Does not alter business_audit_days or other existing retention periods.
    | No prune command yet — inspection and categorization only.
    */
    'audit_logs' => [
        'top_event_limit' => max(1, (int) env('RETENTION_AUDIT_LOGS_TOP_EVENT_LIMIT', 25)),

        'age_cohort_days' => [
            '12_months' => 365,
            '6_months' => 180,
            '365_days' => 365,
            '180_days' => 180,
            '90_days' => 90,
            '30_days' => 30,
        ],

        'categories' => [
            'incoming_email' => [
                'label' => 'Incoming email intake',
                'patterns' => ['incoming_email.%'],
            ],
            'service_case' => [
                'label' => 'Service case lifecycle',
                'patterns' => ['service_case.%'],
                'exclude_patterns' => ['service_case.automation.%'],
            ],
            'automation_radiumbox' => [
                'label' => 'Automation / RadiumBox sync',
                'patterns' => ['radiumbox.%', 'service_case.automation.%'],
            ],
            'notification_comms' => [
                'label' => 'Notifications / communications',
                'patterns' => ['notification.%', 'communication_action.%', 'whatsapp.%'],
            ],
            'finance_payment_refund' => [
                'label' => 'Finance / payment / refund',
                'patterns' => ['refund.%', 'cashfree.%', 'transaction.%', 'commercial.service_%'],
            ],
            'ai_workbench' => [
                'label' => 'AI workbench telemetry',
                'patterns' => ['ai_workbench.%'],
            ],
            'missed_call_recovery' => [
                'label' => 'Missed-call recovery',
                'patterns' => ['missed_call_recovery.%'],
            ],
            'workforce' => [
                'label' => 'Workforce / leave',
                'patterns' => ['workforce.%'],
            ],
            'generic_created_deleted' => [
                'label' => 'Generic created/deleted morph events',
                'exact_events' => ['created', 'deleted'],
            ],
        ],

        'must_keep_families' => [
            'refund' => [
                'label' => 'Refund lifecycle',
                'patterns' => ['refund.%'],
            ],
            'cashfree' => [
                'label' => 'Cashfree payment linking / recovery',
                'patterns' => ['cashfree.%'],
            ],
            'legacy_verification' => [
                'label' => 'Legacy order verification',
                'exact_events' => ['legacy.verification_completed'],
            ],
            'commercial_service' => [
                'label' => 'Commercial service restoration',
                'patterns' => ['commercial.service_%'],
            ],
            'service_case_assignment' => [
                'label' => 'Service case assignment / escalation',
                'exact_events' => [
                    'service_case.assigned',
                    'service_case.reassigned',
                    'service_case.escalated',
                    'service_case.status_changed',
                ],
            ],
            'service_case_automation' => [
                'label' => 'Service case automation milestones',
                'patterns' => ['service_case.automation.%'],
            ],
            'notification_dispatch' => [
                'label' => 'Notification dispatch audit trail',
                'exact_events' => ['notification.dispatched', 'notification.skipped'],
            ],
            'communication_action_lifecycle' => [
                'label' => 'Communication action lifecycle',
                'exact_events' => ['communication_action.lifecycle'],
            ],
            'manual_call_attempt' => [
                'label' => 'Manual call attempt tracking',
                'exact_events' => ['service_case.manual_call_attempt'],
            ],
            'serial_correction' => [
                'label' => 'Serial correction audit trail',
                'exact_events' => ['serial.correct_serial_request_sent', 'serial.corrected_by_ira'],
            ],
            'service_reference' => [
                'label' => 'Service reference assignment / driver guide',
                'exact_events' => ['service_reference.assigned', 'service_reference.driver_guide_sent'],
            ],
            'transaction' => [
                'label' => 'Transaction assignment events',
                'patterns' => ['transaction.%'],
            ],
            'missed_call_recovery' => [
                'label' => 'Missed-call recovery intake trail',
                'patterns' => ['missed_call_recovery.%'],
            ],
        ],

        'telemetry_events' => [
            'ai_workbench.suggestion_viewed',
            'order.viewed',
            'service_case.viewed',
            'radiumbox.enrichment_started',
        ],

        'candidate_cohorts' => [
            'incoming_email_noise' => [
                'label' => 'incoming_email.received / incoming_email.ignored older than 90 days',
                'events' => ['incoming_email.received', 'incoming_email.ignored'],
                'older_than_days' => 90,
            ],
            'telemetry_90d' => [
                'label' => 'Telemetry/view events older than 90 days',
            ],
            'telemetry_180d' => [
                'label' => 'Telemetry/view events older than 180 days',
            ],
            'business_non_email' => [
                'label' => 'Non-email business audit older than business_audit_days',
            ],
        ],

        'logical_safety' => [
            [
                'key' => 'incoming_email_noise',
                'label' => 'incoming_email.received / incoming_email.ignored',
                'classification' => 'safe_candidate',
                'readers' => [
                    'PlatformEmailOperationsService',
                    'OperationsIntegrationHealthService',
                ],
                'notes' => 'Duplicates facts already stored on incoming_email_messages; KPI readers use today-only windows.',
            ],
            [
                'key' => 'telemetry_views',
                'label' => 'ai_workbench / order.viewed / service_case.viewed',
                'classification' => 'safe_candidate',
                'readers' => [
                    'WorkforceActivityContextService',
                    'WorkforceActivityTimelineService',
                ],
                'notes' => 'Low-value view telemetry; no finance or automation idempotency dependency.',
            ],
            [
                'key' => 'radiumbox_enrichment_started',
                'label' => 'radiumbox.enrichment_started',
                'classification' => 'uncertain_review',
                'readers' => [
                    'RadiumBoxSyncTimelineEventMapper',
                    'OperationsCashfreeDeviceEnrichmentService',
                ],
                'notes' => 'Paired with enrichment_completed; review before pruning.',
            ],
            [
                'key' => 'must_keep_finance',
                'label' => 'refund.* / cashfree.* / transaction.* / commercial.service_*',
                'classification' => 'must_keep',
                'readers' => [
                    'CustomerVerificationService',
                    'OperationsCashfreeDeviceEnrichmentService',
                    'RefundCompletionNotificationDecoupleTest consumers',
                ],
                'notes' => 'Permanent business auditability — never delete without archival.',
            ],
            [
                'key' => 'must_keep_operational',
                'label' => 'assignment / automation / notification / comm-action events',
                'classification' => 'must_keep',
                'readers' => [
                    'TeamActivityIncidentResolver',
                    'ReferenceNumberCommunicationService',
                    'AutomationOperationsSnapshotService',
                    'Customer360SlaMetricsService',
                ],
                'notes' => 'Ready Queue ownership, Customer 360, communication-action state, and automation idempotency.',
            ],
            [
                'key' => 'business_non_email_365d',
                'label' => 'Non-email business audit older than 365 days',
                'classification' => 'uncertain_review',
                'readers' => [
                    'OrderActivityTimelineService',
                    'ServiceCaseActivityTimelineService',
                    'OrderIdentityRepairService',
                ],
                'notes' => 'Predicate aligned with business_audit_days; creates timeline gaps on closed cases if pruned without closed-case guard.',
            ],
        ],

        'truncation_issue' => [
            'status' => 'resolved',
            'resolved_in_version' => '4.0.39',
            'old_event' => 'service_case.customer_waiting_already_closed_cleared',
            'new_event' => 'service_case.customer_waiting_closed_cleared',
            'column_limit' => 50,
            'observed_error_count_before_fix' => 413,
            'notes' => 'Failed INSERTs never landed in audit_logs; fix shortens the event constant only.',
        ],
    ],

    /*
    | Historical ignored unknown_customer (fixed received_at cutoff — not created_at).
    | Used by database:retention-inspect-historical-unknown-customer (read-only).
    | One-time scope: emails received through 2026-06-30 (received_at < 2026-07-01 00:00:00).
    | Separate from historical_gmail_noise — does not alter that predicate or prune command.
    */
    'historical_unknown_customer' => [
        'received_at_cutoff' => env('RETENTION_HISTORICAL_UNKNOWN_CUSTOMER_RECEIVED_CUTOFF', '2026-07-01 00:00:00'),
        'ignore_reason' => 'unknown_customer',
        'sample_id_limit' => max(1, (int) env('RETENTION_HISTORICAL_UNKNOWN_CUSTOMER_SAMPLE_ID_LIMIT', 10)),
        'sample_metadata_limit' => max(1, (int) env('RETENTION_HISTORICAL_UNKNOWN_CUSTOMER_SAMPLE_METADATA_LIMIT', 5)),
    ],

];
