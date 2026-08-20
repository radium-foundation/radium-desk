<?php

return [
    'reasoning_provider' => env('IRA_REASONING_PROVIDER', 'rule_based'),

    /*
    |--------------------------------------------------------------------------
    | IRA v2 Overview (CaseIntelligence aggregate + Signal Bar)
    |--------------------------------------------------------------------------
    |
    | When enabled, Customer 360 Overview renders the Phase-1 CaseIntelligence
    | presentation (Signal Bar + human-readable overview). The existing
    | CaseIntelligenceEngine / snapshot builders are reused — not replaced.
    | Default OFF for instant rollback to ira-command-center.
    |
    */
    'v2' => [
        'enabled' => (bool) env('IRA_V2_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer 360 Case Intelligence Engine (IRA v2 foundation)
    |--------------------------------------------------------------------------
    |
    | When enabled, Customer360 executive summary assembly goes through
    | CaseIntelligenceEngine. Disable to instantly roll back to the legacy
    | inline path in Customer360Service.
    |
    */
    'case_intelligence_engine' => [
        // Phase 2: default on so Customer360 intelligence surfaces share one snapshot.
        // Set IRA_CASE_INTELLIGENCE_ENGINE=false for instant legacy rollback.
        'enabled' => (bool) env('IRA_CASE_INTELLIGENCE_ENGINE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer 360 Business Timeline (Timeline tab presentation)
    |--------------------------------------------------------------------------
    |
    | When enabled, the Timeline tab composes operator-visible events into
    | business milestones with collapsed repeats. Disable to instantly roll
    | back to the flat activity feed. Does not affect Case Intelligence.
    |
    */
    'business_timeline' => [
        'enabled' => (bool) env('IRA_BUSINESS_TIMELINE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Case Reasoning Engine (deterministic enrichment)
    |--------------------------------------------------------------------------
    |
    | Thresholds used by CaseReasoningEngine when enriching a
    | CaseIntelligenceSnapshot. No AI / LLM involvement.
    |
    */
    'case_reasoning' => [
        'waiting_too_long_days' => (int) env('IRA_REASONING_WAITING_TOO_LONG_DAYS', 3),
        'serial_pending_too_long_days' => (int) env('IRA_REASONING_SERIAL_PENDING_DAYS', 2),
        'long_inactivity_days' => (int) env('IRA_REASONING_LONG_INACTIVITY_DAYS', 5),
        'case_idle_days' => (int) env('IRA_REASONING_CASE_IDLE_DAYS', 3),
        'customer_silent_days' => (int) env('IRA_REASONING_CUSTOMER_SILENT_DAYS', 2),
        'repeated_reminders_threshold' => (int) env('IRA_REASONING_REPEATED_REMINDERS', 2),
        'repeated_reschedules_threshold' => (int) env('IRA_REASONING_REPEATED_RESCHEDULES', 2),
        'repeated_cancellations_threshold' => (int) env('IRA_REASONING_REPEATED_CANCELLATIONS', 2),
        'multiple_assignments_threshold' => (int) env('IRA_REASONING_MULTIPLE_ASSIGNMENTS', 2),
        'frequent_calls_threshold' => (int) env('IRA_REASONING_FREQUENT_CALLS', 3),
        'contact_without_progress_threshold' => (int) env('IRA_REASONING_CONTACT_WITHOUT_PROGRESS', 3),
        'automation_failure_threshold' => (int) env('IRA_REASONING_AUTOMATION_FAILURES', 2),
        'repeated_repairs_threshold' => (int) env('IRA_REASONING_REPEATED_REPAIRS', 2),
    ],

    'memory' => [
        'retention_days' => 90,
    ],

    'thresholds' => [
        'high_open_cases' => 30,
        'high_scheduled_appointments' => 15,
        'high_waiting_cases' => 50,
        'min_available_staff' => 2,
        'sla_risk_cases' => 3,
        'member_overload_cases' => 8,
        'long_waiting_days' => 7,
        'idle_capacity_minutes' => 15,
    ],

    'communication' => [
        'cooldown_minutes' => (int) env('IRA_NOTIFICATION_COOLDOWN_MINUTES', 60),
        'daily_briefing_time' => env('IRA_DAILY_BRIEFING_TIME', '08:00'),
        'owner_morning_report_time' => env('IRA_OWNER_MORNING_REPORT_TIME', '10:00'),
        'owner_evening_report_time' => env('IRA_OWNER_EVENING_REPORT_TIME', '20:00'),
        'quiet_hours' => [
            'enabled' => true,
            'start' => '21:00',
            'end' => '08:00',
        ],
        'assignment_telegram_batch' => [
            'enabled' => (bool) env('IRA_ASSIGNMENT_TELEGRAM_BATCH_ENABLED', true),
            'delay_minutes' => max(1, (int) env('IRA_ASSIGNMENT_TELEGRAM_BATCH_DELAY_MINUTES', 5)),
        ],
    ],

    'watchdog' => [
        'enabled' => (bool) env('IRA_WATCHDOG_ENABLED', true),
        'schedule_interval_minutes' => max(1, (int) env('IRA_WATCHDOG_INTERVAL_MINUTES', 5)),
        'automation_failure_threshold' => max(1, (int) env('IRA_WATCHDOG_AUTOMATION_FAILURE_THRESHOLD', 3)),
        'interakt_failure_threshold' => max(1, (int) env('IRA_WATCHDOG_INTERAKT_FAILURE_THRESHOLD', 3)),
        'radiumbox_min_success_rate' => (float) env('IRA_WATCHDOG_RADIUMBOX_MIN_SUCCESS_RATE', 80),
    ],
];
