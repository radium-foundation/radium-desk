<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard Live Update Mode
    |--------------------------------------------------------------------------
    |
    | poll   — 30-second HTTP polling only (legacy behaviour)
    | reverb — Laravel Reverb WebSocket updates only
    | auto   — Reverb with automatic fallback to polling on disconnect
    |
    */

    'live_mode' => env('DASHBOARD_LIVE_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Polling Interval (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Used when live_mode is "poll", or as a fallback when Reverb disconnects
    | in "auto" mode.
    |
    */

    'poll_interval_ms' => (int) env('DASHBOARD_POLL_INTERVAL_MS', 30000),

    /*
    |--------------------------------------------------------------------------
    | Service Cases Page Sizes
    |--------------------------------------------------------------------------
    |
    | Initial rows on dashboard render (e.g. 35). Each "Load More" click
    | appends service_cases_load_more_size rows (e.g. 25). Live refresh
    | requests use the current loaded count.
    |
    */

    'service_cases_page_size' => (int) env('DASHBOARD_SERVICE_CASES_PAGE_SIZE', 35),

    'service_cases_load_more_size' => (int) env('DASHBOARD_SERVICE_CASES_LOAD_MORE_SIZE', 25),

];
