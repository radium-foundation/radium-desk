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

];
