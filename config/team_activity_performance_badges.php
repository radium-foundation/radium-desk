<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Team Activity performance badges (presentation only)
    |--------------------------------------------------------------------------
    |
    | Reads Performance Intelligence snapshots — never recalculates metrics.
    | Does not expose scores, RPE, or Performance Intelligence numbers.
    |
    | Requires snapshots (PERFORMANCE_INTELLIGENCE_ENABLED + daily capture).
    |
    */
    'enabled' => (bool) env('TEAM_ACTIVITY_PERFORMANCE_BADGES', false),

    'max_badges' => 3,

    /*
    | Display order when more than max_badges qualify (first wins).
    */
    'priority' => [
        'exceptional_day',
        'extra_contribution',
        'critical_work',
        'team_helper',
    ],

    'badges' => [
        'extra_contribution' => [
            'emoji' => '🌙',
            'title' => 'Extra Contribution',
            'tooltip' => "Meaningful work completed outside scheduled hours.\nOperational recognition only.",
            // null → performance_intelligence.commitment.outcome_floor
            'outcome_raw_floor' => null,
        ],
        'team_helper' => [
            'emoji' => '🤝',
            'title' => 'Team Helper',
            'tooltip' => "Received helper credit for meaningful teammate support.\nOperational recognition only.",
            // Reserved until shared contribution helper credit ships.
            'enabled' => false,
        ],
        'critical_work' => [
            'emoji' => '🛡',
            'title' => 'Critical Work',
            'tooltip' => "Handled critical or escalation work.\nOperational recognition only.",
            // Reserved until a reliable escalation/complexity signal exists on snapshots.
            'enabled' => false,
        ],
        'exceptional_day' => [
            'emoji' => '🔥',
            'title' => 'Exceptional Day',
            'tooltip' => "Standout operational day by calibrated thresholds.\nOperational recognition only.",
        ],
    ],

    /*
    | Exceptional Day — internal thresholds only; never shown in UI.
    */
    'exceptional' => [
        'composite_min' => (float) env('TEAM_ACTIVITY_BADGE_EXCEPTIONAL_COMPOSITE_MIN', 70),
        'outcome_min' => null,
        'quality_min' => null,
        'require_all' => false,
    ],

];
