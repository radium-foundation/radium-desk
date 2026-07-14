@props([
    'stats',
])

@php
    use App\Services\Operations\OperationsRoleService;

    $items = [];
    $currentUser = $viewer ?? auth()->user();
    $roles = app(OperationsRoleService::class);
    $usesSupportQueues = $currentUser !== null && $roles->usesSupportQueues($currentUser);
    $usesAdminQueues = $currentUser !== null && $roles->usesAdminQueues($currentUser);

    if ($usesSupportQueues) {
        // Support agent cards are rendered via agent-action-cards partial.
    }

    if ($currentUser?->can('incidents.view') && ! $usesSupportQueues) {
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
            'href' => route('dashboard', ['filter' => 'overdue']).'#dashboard-service-cases-panel',
        ];

        $items[] = [
            'label' => 'Customer Waiting',
            'value' => $stats['waiting_cases'] ?? 0,
            'icon' => 'bi-hourglass-split',
            'color' => 'warning',
            'href' => route('dashboard', ['queue' => 'waiting_customer']).'#dashboard-service-cases-panel',
        ];
    }

    if ($usesAdminQueues && isset($stats['total_active_cases'])) {
        $items[] = [
            'label' => 'Total Active Cases',
            'value' => $stats['total_active_cases'],
            'icon' => 'bi-clipboard-data',
            'color' => 'info',
            'href' => route('incidents.index'),
        ];
    }

    if ($currentUser?->can('refunds.view') && isset($stats['pending_refunds']) && ! $usesSupportQueues) {
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
@if($usesSupportQueues ?? false)
    @include('dashboard.partials.agent-action-cards', ['stats' => $stats])
@else
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
@endif
</div>
