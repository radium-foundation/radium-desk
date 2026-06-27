@props([
    'stats',
])

@php
    $onlineCount = $stats['online_count'] ?? 0;
    $onlineUsers = $stats['online_users'] ?? collect();

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

    if (isset($stats['audit_log_count'])) {
        $items[] = [
            'label' => 'Audit Log Entries',
            'value' => $stats['audit_log_count'],
            'icon' => 'bi-journal-text',
            'color' => 'secondary',
        ];
    }
@endphp

<div class="dashboard-admin-metrics">
    <h2 class="dashboard-section-title dashboard-section-title--muted mb-0">Admin Metrics</h2>
    <div class="dashboard-kpi-strip dashboard-kpi-strip--admin" role="region" aria-label="Admin metrics">
        @if(isset($stats['total_users']))
            <div data-admin-kpi-slot="total-users">
                @include('dashboard.partials.kpi-strip-item', [
                    'label' => 'Total Users',
                    'value' => $stats['total_users'],
                    'icon' => 'bi-people',
                    'color' => 'info',
                    'itemClass' => 'dashboard-kpi-item--total-users',
                ])
            </div>
        @endif

        <div data-admin-kpi-slot="online-users">
            @include('dashboard.partials.kpi-strip-online-users', [
                'onlineCount' => $onlineCount,
                'onlineUsers' => $onlineUsers,
                'totalUsers' => $stats['total_users'] ?? null,
            ])
        </div>

        @foreach($items as $item)
            @include('dashboard.partials.kpi-strip-item', $item)
        @endforeach
    </div>
</div>
