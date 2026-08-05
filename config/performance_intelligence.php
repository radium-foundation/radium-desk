<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Performance Intelligence (Phase 0 — Shadow Mode)
    |--------------------------------------------------------------------------
    |
    | When disabled (default), the engine, snapshot command, and Super Admin
    | screen must have zero runtime impact on dashboards and existing KPIs.
    |
    | Blueprint: docs/radium-performance-engine-blueprint.md
    |
    */
    'enabled' => (bool) env('PERFORMANCE_INTELLIGENCE_ENABLED', false),

    /*
    | Schema / calculator version stored on every snapshot for explainability.
    */
    'version' => 'phase0.1',

    /*
    | Pillar weights for the composite RPE Index (must sum to 1.0).
    | Source: docs/radium-performance-engine-blueprint.md §3.2
    */
    'weights' => [
        'outcome' => 0.35,
        'reach' => 0.20,
        'contribution' => 0.20,
        'commitment' => 0.10,
        'quality' => 0.15,
    ],

    /*
    | Raw point awards (transparent; used before 0–100 normalization).
    */
    'points' => [
        'resolved' => 8,
        'closed' => 5,
        'closed_after_resolve_same_day' => 2,
        'refund_decision' => 4,
        'answered_call' => 4,
        'manual_whatsapp' => 3,
        'human_email' => 3,
        'manual_remark' => 1,
        'status_intermediate' => 1,
        'assign_or_escalate' => 1,
    ],

    /*
    | Anti-gaming caps (per employee per day unless noted).
    */
    'caps' => [
        'remarks_per_case' => 3,
        'status_points_per_case' => 10,
        'assign_per_case' => 1,
        'remarks_total' => 30,
        'status_intermediate_total' => 40,
    ],

    /*
    | Normalization ceilings: raw points at/above these map to pillar 100.
    */
    'normalize' => [
        'outcome' => 40,
        'reach' => 12,
        'contribution' => 40,
        'commitment' => 20,
        'quality' => 100,
    ],

    /*
    | Commitment: outside-roster day requires this Outcome raw floor for points.
    */
    'commitment' => [
        'outcome_floor' => 8,
        'weekly_off_or_holiday_points' => 12,
        'leave_points' => 16,
        'overtime_minutes_soft_cap' => 120,
        'overtime_soft_points' => 4,
    ],

    /*
    | Quality: start at 100; subtract penalties (floor 0).
    */
    'quality' => [
        'base' => 100,
        'reopen_penalty' => 15,
        'min_resolves_for_rank' => 1,
    ],

    /*
    | Snapshot schedule (only when enabled).
    */
    'snapshot_time' => env('PERFORMANCE_INTELLIGENCE_SNAPSHOT_TIME', '00:15'),

];
