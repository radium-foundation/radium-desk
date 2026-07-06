<?php

return [
    'backlog_stale_hours' => (int) env('OPERATIONS_BACKLOG_STALE_HOURS', 18),

    'queues' => [
        'action_required' => [
            'label' => 'Ready Queue',
            'icon' => 'bi-lightning-charge-fill',
            'tone' => 'warning',
        ],
        'pending_review' => [
            'label' => 'Pending Review',
            'icon' => 'bi-inbox',
            'tone' => 'secondary',
        ],
        'scheduled' => [
            'label' => 'Scheduled',
            'icon' => 'bi-calendar-check-fill',
            'tone' => 'info',
        ],
        'waiting_customer' => [
            'label' => 'Waiting Customer',
            'icon' => 'bi-hourglass-split',
            'tone' => 'secondary',
        ],
        'attention' => [
            'label' => 'Attention',
            'icon' => 'bi-exclamation-triangle-fill',
            'tone' => 'danger',
        ],
        'hardware' => [
            'label' => 'Hardware',
            'icon' => 'bi-box-seam',
            'tone' => 'primary',
        ],
        'completed' => [
            'label' => 'Completed',
            'icon' => 'bi-check-circle-fill',
            'tone' => 'success',
        ],
        'my_work' => [
            'label' => 'My Work',
            'icon' => 'bi-person-check-fill',
            'tone' => 'primary',
        ],
    ],

    'roles' => [
        'superadmin' => [
            'label' => 'Owner / Super Admin',
            'description' => 'Full system visibility, analytics, and configuration.',
        ],
        'admin' => [
            'label' => 'Operations Admin',
            'description' => 'Manage queues, assign cases, approve operations, and oversee the team.',
        ],
        'operations_admin' => [
            'label' => 'Operations Admin',
            'description' => 'Manage queues, assign cases, approve operations, and oversee the team.',
        ],
        'agent' => [
            'label' => 'Support Specialist',
            'description' => 'Handle assigned service cases and scheduled support work.',
        ],
        'support_specialist' => [
            'label' => 'Support Specialist',
            'description' => 'Handle assigned service cases and scheduled support work.',
        ],
        'customer_coordinator' => [
            'label' => 'Customer Coordinator',
            'description' => 'Customer follow-up, communication, and waiting-state coordination.',
        ],
        'hardware_team' => [
            'label' => 'Hardware Team',
            'description' => 'RDE hardware orders and device processing.',
        ],
    ],

    'hardware_order_prefix' => 'RDE',
];
