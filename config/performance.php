<?php

return [

    'profiles' => [
        'high_performance' => [
            'label' => 'High Performance',
            'description' => 'Faster polling for the most responsive experience. Higher server load.',
            'recommended' => false,
            'values' => [
                'performance.polling.dashboard_live_ms' => 15000,
                'performance.polling.notification_ms' => 10000,
                'performance.polling.operations_ms' => 15000,
                'performance.polling.operations_full_refresh_ms' => 60000,
                'performance.polling.customer360_timeline_ms' => 15000,
                'performance.polling.customer360_device_sync_ms' => 5000,
                'performance.polling.presence_heartbeat_seconds' => 60,
                'performance.polling.agent_reminder_seconds' => 30,
            ],
        ],
        'balanced' => [
            'label' => 'Balanced',
            'description' => 'Recommended balance of responsiveness and resource usage.',
            'recommended' => true,
            'values' => [
                'performance.polling.dashboard_live_ms' => 30000,
                'performance.polling.notification_ms' => 20000,
                'performance.polling.operations_ms' => 30000,
                'performance.polling.operations_full_refresh_ms' => 120000,
                'performance.polling.customer360_timeline_ms' => 30000,
                'performance.polling.customer360_device_sync_ms' => 10000,
                'performance.polling.presence_heartbeat_seconds' => 120,
                'performance.polling.agent_reminder_seconds' => 60,
            ],
        ],
        'low_resource' => [
            'label' => 'Low Resource',
            'description' => 'Reduced polling frequency to minimize server load.',
            'recommended' => false,
            'values' => [
                'performance.polling.dashboard_live_ms' => 60000,
                'performance.polling.notification_ms' => 45000,
                'performance.polling.operations_ms' => 60000,
                'performance.polling.operations_full_refresh_ms' => 240000,
                'performance.polling.customer360_timeline_ms' => 60000,
                'performance.polling.customer360_device_sync_ms' => 30000,
                'performance.polling.presence_heartbeat_seconds' => 300,
                'performance.polling.agent_reminder_seconds' => 120,
            ],
        ],
        'manual' => [
            'label' => 'Manual',
            'description' => 'Customize each polling interval individually.',
            'recommended' => false,
            'values' => [],
        ],
    ],

    'polling_keys' => [
        'performance.polling.dashboard_live_ms',
        'performance.polling.notification_ms',
        'performance.polling.operations_ms',
        'performance.polling.operations_full_refresh_ms',
        'performance.polling.customer360_timeline_ms',
        'performance.polling.customer360_device_sync_ms',
        'performance.polling.presence_heartbeat_seconds',
        'performance.polling.agent_reminder_seconds',
        'performance.polling.executive_dashboard_seconds',
    ],

    'fallbacks' => [
        'performance.polling.dashboard_live_ms' => 30000,
        'performance.polling.notification_ms' => 20000,
        'performance.polling.operations_ms' => 30000,
        'performance.polling.operations_full_refresh_ms' => 120000,
        'performance.polling.customer360_timeline_ms' => 30000,
        'performance.polling.customer360_device_sync_ms' => 10000,
        'performance.polling.presence_heartbeat_seconds' => 120,
        'performance.polling.agent_reminder_seconds' => 60,
        'performance.polling.executive_dashboard_seconds' => 60,
    ],

    'future' => [
        'queue' => [
            'chunk_size' => ['enabled' => false],
        ],
        'cache' => [
            'default_ttl_seconds' => ['enabled' => false],
        ],
        'api' => [
            'timeout_seconds' => ['enabled' => false],
        ],
        'automation' => [
            'scheduler_interval_seconds' => ['enabled' => false],
        ],
    ],

];
