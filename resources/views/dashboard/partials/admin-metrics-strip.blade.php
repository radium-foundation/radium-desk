@props([
    'stats',
])

@php
    $items = [
        [
            'label' => 'Total Orders',
            'value' => $stats['total_orders'],
            'icon' => 'bi-box-seam',
            'color' => 'secondary',
        ],
        [
            'label' => config('ui.service_case.resolved_label'),
            'value' => $stats['resolved_incidents'],
            'icon' => 'bi-check-circle',
            'color' => 'success',
        ],
        [
            'label' => config('ui.service_case.closed_label'),
            'value' => $stats['closed_incidents'],
            'icon' => 'bi-archive',
            'color' => 'secondary',
        ],
    ];

    if (isset($stats['approved_refunds'])) {
        $items[] = [
            'label' => 'Approved Refunds',
            'value' => $stats['approved_refunds'],
            'icon' => 'bi-check-circle',
            'color' => 'success',
        ];
    }

    if (isset($stats['rejected_refunds'])) {
        $items[] = [
            'label' => 'Rejected Refunds',
            'value' => $stats['rejected_refunds'],
            'icon' => 'bi-x-circle',
            'color' => 'danger',
        ];
    }

    if (isset($stats['approval_numbers'])) {
        $items[] = [
            'label' => 'Approval Numbers',
            'value' => $stats['approval_numbers'],
            'icon' => 'bi-check2-square',
            'color' => 'info',
        ];
    }

    if (isset($stats['total_users'])) {
        $items[] = [
            'label' => 'Total Users',
            'value' => $stats['total_users'],
            'icon' => 'bi-people',
            'color' => 'info',
        ];
    }

    if (isset($stats['audit_log_count'])) {
        $items[] = [
            'label' => 'Audit Log Entries',
            'value' => $stats['audit_log_count'],
            'icon' => 'bi-journal-text',
            'color' => 'secondary',
        ];
    }
@endphp

<div class="dashboard-admin-metrics mb-2">
    <h2 class="dashboard-section-title h6 mb-1">Admin Metrics</h2>
    <div class="dashboard-kpi-strip dashboard-kpi-strip--secondary" role="region" aria-label="Admin metrics">
        @foreach($items as $item)
            @include('dashboard.partials.kpi-strip-item', $item)
        @endforeach
    </div>
</div>
