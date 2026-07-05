<?php

return [

    'active_threshold_minutes' => (int) env('PRESENCE_ACTIVE_THRESHOLD_MINUTES', 5),

    'idle_threshold_minutes' => (int) env('PRESENCE_IDLE_THRESHOLD_MINUTES', 15),

    'away_timeout_minutes' => (int) env('PRESENCE_AWAY_TIMEOUT_MINUTES', 15),

    'heartbeat_interval_seconds' => (int) env('PRESENCE_HEARTBEAT_INTERVAL_SECONDS', 120),

    'heartbeat_enabled' => (bool) env('PRESENCE_HEARTBEAT_ENABLED', true),

];
