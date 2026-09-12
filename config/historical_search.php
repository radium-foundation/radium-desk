<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical Historical Search (radium_hist)
    |--------------------------------------------------------------------------
    |
    | Read-only, non-critical Global Search provider. Desk remains operational
    | when disabled, timed out, or circuit-open.
    |
    */

    'enabled' => (bool) env('HISTORICAL_SEARCH_ENABLED', false),

    'connection' => env('HISTORICAL_SEARCH_DB_CONNECTION', 'radium_hist'),

    'timeout_ms' => max(50, (int) env('HISTORICAL_SEARCH_TIMEOUT_MS', 400)),

    'max_results' => max(1, min(25, (int) env('HISTORICAL_SEARCH_MAX_RESULTS', 10))),

    'circuit_breaker' => [
        'failure_threshold' => max(1, (int) env('HISTORICAL_SEARCH_CB_FAILURE_THRESHOLD', 5)),
        'open_seconds' => max(5, (int) env('HISTORICAL_SEARCH_CB_OPEN_SECONDS', 30)),
        'window_seconds' => max(10, (int) env('HISTORICAL_SEARCH_CB_WINDOW_SECONDS', 60)),
        'cache_key' => 'historical_search.circuit_breaker',
    ],

];
