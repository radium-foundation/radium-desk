@props([
    'onlineCount' => 0,
    'onlineUsers',
    'totalUsers' => null,
])

@php
    use App\Services\DashboardService;
    use Illuminate\Support\Str;

    $dashboardService = app(DashboardService::class);
    $sortedUsers = collect($onlineUsers ?? [])
        ->sortBy(
            fn ($user) => Str::lower($dashboardService->onlineUserDisplayName($user)),
            SORT_NATURAL,
        )
        ->values();

    $tooltipFooter = $totalUsers !== null
        ? "{$onlineCount} of {$totalUsers} users"
        : null;

    $tooltipHtml = $onlineCount === 0
        ? view('dashboard.partials.premium-tooltip', [
            'title' => 'No active users',
            'footer' => $tooltipFooter,
        ])->render()
        : view('dashboard.partials.premium-tooltip', [
            'title' => 'Currently Online',
            'lines' => $sortedUsers
                ->map(fn ($user) => '🟢 '.$dashboardService->onlineUserDisplayName($user))
                ->all(),
            'footer' => $tooltipFooter,
        ])->render();
@endphp

<div
    @class([
        'dashboard-kpi-item',
        'dashboard-kpi-item--online-users',
        'dashboard-u-surface-card',
        'dashboard-u-transition',
    ])
    data-bs-toggle="tooltip"
    data-bs-placement="top"
    data-bs-html="true"
    data-bs-container="body"
    data-bs-boundary="viewport"
    data-bs-custom-class="dashboard-premium-tooltip-wrapper"
    data-bs-title="{{ e($tooltipHtml) }}"
>
    <div class="dashboard-kpi-icon dashboard-kpi-icon--spacer" aria-hidden="true"></div>
    <div class="dashboard-kpi-content">
        <div class="dashboard-kpi-label">Online Users</div>
        <div class="dashboard-kpi-value dashboard-kpi-value--with-status">
            <span class="dashboard-online-status-dot" aria-hidden="true"></span>
            <span class="dashboard-kpi-value-number">{{ number_format($onlineCount) }}</span>
        </div>
    </div>
</div>
