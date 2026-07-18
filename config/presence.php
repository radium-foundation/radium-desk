<?php

return [

    'active_threshold_minutes' => (int) env('PRESENCE_ACTIVE_THRESHOLD_MINUTES', 5),

    'idle_threshold_minutes' => (int) env('PRESENCE_IDLE_THRESHOLD_MINUTES', 15),

    'away_timeout_minutes' => (int) env('PRESENCE_AWAY_TIMEOUT_MINUTES', 15),

    'heartbeat_interval_seconds' => (int) env('PRESENCE_HEARTBEAT_INTERVAL_SECONDS', 120),

    'heartbeat_enabled' => (bool) env('PRESENCE_HEARTBEAT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | View event cooldown (seconds)
    |--------------------------------------------------------------------------
    |
    | Repeated order.viewed / service_case.viewed audits for the same entity
    | are suppressed until this cooldown elapses. Different entities always log.
    |
    */
    'view_event_cooldown_seconds' => (int) env('PRESENCE_VIEW_EVENT_COOLDOWN_SECONDS', 60),

];
