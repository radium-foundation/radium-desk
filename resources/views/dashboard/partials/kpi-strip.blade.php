@props([
    'stats',
])

@php
    $items = [];
    $currentUser = $viewer ?? auth()->user();

    if ($currentUser?->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_AGENT)) {
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
            'href' => route('dashboard', ['queue' => 'action_required']),
        ];
        $items[] = [
            'label' => 'High Priority',
            'value' => $stats['high_priority_cases'],
            'icon' => 'bi-flag-fill',
            'color' => 'danger',
            'href' => route('dashboard', ['queue' => 'attention']),
        ];
        $items[] = [
            'label' => 'Total Active Cases',
            'value' => $stats['total_active_cases'],
            'icon' => 'bi-clipboard-data',
            'color' => 'info',
            'href' => route('incidents.index'),
        ];
    }

    if ($currentUser?->can('incidents.view')) {
        $items[] = [
            'label' => 'Open',
            'value' => $stats['open_cases'] ?? $stats['open_incidents'] ?? 0,
            'icon' => 'bi-inbox',
            'color' => 'primary',
            'href' => route('dashboard', ['queue' => 'action_required']).'#dashboard-service-cases-panel',
        ];

        $items[] = [
            'label' => 'Overdue',
            'value' => $stats['overdue_cases'] ?? 0,
            'icon' => 'bi-exclamation-octagon-fill',
            'color' => 'danger',
            'href' => route('dashboard', ['queue' => 'attention']).'#dashboard-service-cases-panel',
        ];

        $items[] = [
            'label' => 'Waiting',
            'value' => $stats['waiting_cases'] ?? 0,
            'icon' => 'bi-hourglass-split',
            'color' => 'warning',
            'href' => route('dashboard', ['queue' => 'waiting_customer']).'#dashboard-service-cases-panel',
        ];
    }

    if ($currentUser?->can('refunds.view') && isset($stats['pending_refunds'])) {
        $items[] = [
            'label' => 'Refunds',
            'value' => $stats['pending_refunds'],
            'icon' => 'bi-cash-stack',
            'color' => 'warning',
            'href' => route('refunds.index', ['status' => 'pending']),
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
