@props([
    'stats',
])

@php
    $items = [];

    if (auth()->user()?->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_AGENT)) {
        $items[] = [
            'label' => 'My Active Work',
            'value' => $stats['my_active_cases'],
            'icon' => 'bi-briefcase',
            'color' => 'primary',
            'href' => route('incidents.index'),
        ];
        $items[] = [
            'label' => 'Pending Admin',
            'value' => $stats['waiting_for_admin'],
            'icon' => 'bi-hourglass-split',
            'color' => 'warning',
            'href' => route('dashboard', ['filter' => 'pending_admin']),
        ];
        $items[] = [
            'label' => 'High Priority',
            'value' => $stats['high_priority_cases'],
            'icon' => 'bi-flag-fill',
            'color' => 'danger',
            'href' => route('dashboard', ['filter' => 'high_priority']),
        ];
        $items[] = [
            'label' => 'Total Active Cases',
            'value' => $stats['total_active_cases'],
            'icon' => 'bi-clipboard-data',
            'color' => 'info',
            'href' => route('incidents.index'),
        ];
    }

    if (isset($stats['pending_approvals'])) {
        $items[] = [
            'label' => 'Pending Approvals',
            'value' => $stats['pending_approvals'],
            'icon' => 'bi-check2-square',
            'color' => 'primary',
            'href' => route('approvals.index'),
        ];
    }

    if (isset($stats['pending_refunds'])) {
        $items[] = [
            'label' => 'Pending Refunds',
            'value' => $stats['pending_refunds'],
            'icon' => 'bi-hourglass-split',
            'color' => 'warning',
            'href' => route('refunds.index', ['status' => 'pending']),
        ];
    }

    $items[] = [
        'label' => 'Open Cases',
        'value' => $stats['open_incidents'],
        'icon' => 'bi-exclamation-triangle',
        'color' => 'danger',
        'href' => route('incidents.index'),
    ];

    if (auth()->user()?->can('incidents.view') && isset($stats['overdue_cases'])) {
        $items[] = [
            'label' => 'Overdue',
            'value' => $stats['overdue_cases'],
            'icon' => 'bi-exclamation-octagon-fill',
            'color' => 'danger',
            'href' => route('dashboard', ['filter' => 'overdue']),
        ];
    }

    if (auth()->user()?->can('incidents.view') && isset($stats['warning_cases'])) {
        $items[] = [
            'label' => 'Warning',
            'value' => $stats['warning_cases'],
            'icon' => 'bi-exclamation-triangle-fill',
            'color' => 'warning',
            'href' => route('dashboard', ['filter' => 'warning']),
        ];
    }

    $onlineCount = $stats['online_count'] ?? 0;
    $onlineUsers = $stats['online_users'] ?? collect();
@endphp

<div class="dashboard-kpi-strip" role="region" aria-label="Dashboard metrics">
@if(request()->expectsJson())
    @if(isset($stats['total_users']))
        @include('dashboard.partials.kpi-strip-item', [
            'label' => 'Total Users',
            'value' => $stats['total_users'],
            'icon' => 'bi-people',
            'color' => 'info',
            'itemClass' => 'dashboard-kpi-item--total-users',
        ])
    @endif

    @include('dashboard.partials.kpi-strip-online-users', [
        'onlineCount' => $onlineCount,
        'onlineUsers' => $onlineUsers,
        'totalUsers' => $stats['total_users'] ?? null,
    ])
@endif

    @foreach($items as $item)
        @include('dashboard.partials.kpi-strip-item', $item)
    @endforeach
</div>
