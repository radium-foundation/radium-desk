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

    /*
    |--------------------------------------------------------------------------
    | Operations Workspace Soft Switch (Phase 1)
    |--------------------------------------------------------------------------
    |
    | When true, queue/KPI links that stay on /dashboard soft-switch the
    | service-cases panel via AJAX + History API instead of a full reload.
    | Set false to restore legacy full-page navigation (rollback).
    |
    */

    'operations_workspace_soft_switch' => (bool) env('DASHBOARD_OPERATIONS_WORKSPACE_SOFT_SWITCH', true),

    /*
    |--------------------------------------------------------------------------
    | Operations Workspace Phase 2 — Embedded Listings
    |--------------------------------------------------------------------------
    |
    | When true, Total Active Cases and Refunds KPIs soft-switch into embedded
    | listing panels on the Dashboard. Set false to restore full navigation to
    | /incidents and /refunds (Phase 1 soft-switch for case queues remains).
    |
    */

    'operations_workspace_phase2_embed' => (bool) env('DASHBOARD_OPERATIONS_WORKSPACE_PHASE2_EMBED', true),

    /*
    |--------------------------------------------------------------------------
    | Operations Workspace Phase 3 — Native Dashboard Layouts
    |--------------------------------------------------------------------------
    |
    | When true, Active Cases and Refunds use native Dashboard Operations
    | Workspace chrome (same visual language as Ready Queue). Set false to
    | restore Phase 2 legacy listing markup inside the embed host.
    |
    */

    'operations_workspace_phase3_native' => (bool) env('DASHBOARD_OPERATIONS_WORKSPACE_PHASE3_NATIVE', true),

    /*
    |--------------------------------------------------------------------------
    | Operator Dashboard Snapshot Cache
    |--------------------------------------------------------------------------
    |
    | Cross-request cache for the active-incident hydrate used by KPI strip,
    | filter counts, and service-case rows. Shared cache stores a plain-array
    | projection (never Eloquent models). TTL is clamped to 15–30 seconds.
    |
    */

    'snapshot_cache_enabled' => (bool) env('DASHBOARD_SNAPSHOT_CACHE_ENABLED', true),

    'snapshot_cache_ttl_seconds' => (int) env('DASHBOARD_SNAPSHOT_CACHE_TTL_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Slow-Changing Dashboard Scalars Cache
    |--------------------------------------------------------------------------
    |
    | Caches Order / User / AuditLog table COUNTs used by admin KPI stats.
    | TTL is clamped to 15–60 seconds. Invalidation is TTL-based.
    |
    */

    'slow_scalars_cache_ttl_seconds' => (int) env('DASHBOARD_SLOW_SCALARS_CACHE_TTL_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Hardware dashboard async counts cache
    |--------------------------------------------------------------------------
    |
    | Short TTL for hardware workspace filter/scope counts on the async rows API.
    | TTL-based invalidation only.
    |
    */

    'hardware_counts_ttl_seconds' => (int) env('DASHBOARD_HARDWARE_COUNTS_TTL_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Hardware dashboard progressive row loading
    |--------------------------------------------------------------------------
    |
    | Initial page size applies when the async client sends hw_progressive=1
    | (first batch). Later pages use hardware_rows_per_page. Skeleton rows on
    | the deferred shell use hardware_skeleton_row_count (not real data).
    |
    */

    'hardware_rows_initial_per_page' => max(1, (int) env('DASHBOARD_HARDWARE_ROWS_INITIAL_PER_PAGE', 10)),
    'hardware_rows_per_page' => max(1, (int) env('DASHBOARD_HARDWARE_ROWS_PER_PAGE', 40)),
    'hardware_skeleton_row_count' => max(1, (int) env('DASHBOARD_HARDWARE_SKELETON_ROW_COUNT', 10)),
    'hardware_search_debounce_ms' => max(0, (int) env('DASHBOARD_HARDWARE_SEARCH_DEBOUNCE_MS', 300)),

];
