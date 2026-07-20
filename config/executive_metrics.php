<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Metric polarity (higher_better | lower_better | neutral)
    |--------------------------------------------------------------------------
    |
    | Used by TrendService and ExecutiveInsightEngine to interpret direction.
    | Unknown metric keys default to neutral.
    |
    */
    'polarity' => [
        'open_cases' => 'lower_better',
        'critical_cases' => 'lower_better',
        'customers_waiting' => 'lower_better',
        'refund_queue' => 'lower_better',
        'active_agents' => 'higher_better',
        'orders_today' => 'higher_better',
        'resolved_today' => 'higher_better',
        'appointments_today' => 'higher_better',
    ],

    'comparison_label' => 'Compared to yesterday',

    'neutral_threshold_percent' => 1.0,

    'insight' => [
        'min_percent' => 10.0,
        'weekly_average_band' => 0.15,
        'max_insights' => 5,
    ],
];
