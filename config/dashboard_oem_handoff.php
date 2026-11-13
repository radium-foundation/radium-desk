<?php

return [
    /**
     * Master gate for dashboard OEM handoff sessions (serial + optional Mantra result return).
     */
    'enabled' => filter_var(env('DASHBOARD_OEM_HANDOFF_ENABLED', false), FILTER_VALIDATE_BOOL),

    /**
     * When false (default), extension may prefill Mantra only; DOM result capture is rejected server-side.
     */
    'mantra_result_capture_enabled' => filter_var(
        env('DASHBOARD_OEM_HANDOFF_MANTRA_CAPTURE', false),
        FILTER_VALIDATE_BOOL,
    ),

    'session_ttl_seconds' => (int) env('DASHBOARD_OEM_HANDOFF_SESSION_TTL_SECONDS', 600),

    'start_rate_limit_per_minute' => (int) env('DASHBOARD_OEM_HANDOFF_START_RATE_LIMIT', 20),
];
