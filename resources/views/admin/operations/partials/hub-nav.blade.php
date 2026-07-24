@props([
    'active' => 'today',
    'onControlCenter' => false,
])

@php
    use Database\Seeders\RolePermissionSeeder;

    $user = auth()->user();
    $showsOperationsHubNav = $user?->hasAnyRole([
        RolePermissionSeeder::ROLE_ADMIN,
        RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        RolePermissionSeeder::ROLE_SUPERADMIN,
    ]) && (
        $user->can('operations-dashboard.view')
        || $user->can('automation-operations.view')
        || $user->can('viewAny', App\Models\CashfreeWebhookLog::class)
    );

    $controlCenterTabs = [
        'today' => [
            'label' => 'Today',
            'liveGroup' => 'today',
        ],
        'team' => [
            'label' => 'Team',
            'liveGroup' => 'team',
        ],
        'performance' => [
            'label' => 'Performance',
            'liveGroup' => 'performance',
        ],
        'system' => [
            'label' => 'System',
            'liveGroup' => 'system',
        ],
    ];
@endphp

@if($showsOperationsHubNav)
    <nav
        @class([
            'operations-hub-nav',
            'mb-4' => ! $onControlCenter,
            'operations-hub-nav--card-header' => $onControlCenter,
        ])
        aria-label="Operations hub"
    >
        <ul
            @class([
                'nav',
                'nav-tabs',
                'operations-hub-tabs',
                'flex-nowrap',
                'overflow-auto',
                'card-header-tabs operations-dashboard-tablist' => $onControlCenter,
            ])
            @if($onControlCenter) id="operations-dashboard-tabs" role="tablist" @endif
        >
            @can('operations-dashboard.view')
                @foreach($controlCenterTabs as $key => $tab)
                    <li class="nav-item" role="presentation">
                        @if($onControlCenter)
                            <button
                                @class(['nav-link', 'active' => $key === 'today'])
                                id="operations-tab-{{ $key }}"
                                data-bs-toggle="tab"
                                data-bs-target="#operations-pane-{{ $key }}"
                                data-operations-live-group="{{ $tab['liveGroup'] }}"
                                type="button"
                                role="tab"
                                aria-controls="operations-pane-{{ $key }}"
                                aria-selected="{{ $key === 'today' ? 'true' : 'false' }}"
                            >
                                {{ $tab['label'] }}
                            </button>
                        @else
                            <a
                                @class(['nav-link', 'active' => $active === $key])
                                href="{{ route('admin.operations.index', ['hub_tab' => $key]) }}"
                                @if($active === $key) aria-current="page" @endif
                            >
                                {{ $tab['label'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            @endcan

            @can('automation-operations.view')
                @if($onControlCenter)
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="operations-tab-automation"
                            data-bs-toggle="tab"
                            data-bs-target="#operations-pane-automation"
                            data-operations-live-group="automation"
                            type="button"
                            role="tab"
                            aria-controls="operations-pane-automation"
                            aria-selected="false"
                        >
                            Automation
                        </button>
                    </li>
                @else
                    <li class="nav-item" role="presentation">
                        <a
                            @class(['nav-link', 'active' => in_array($active, ['automation-health', 'automation'], true)])
                            href="{{ route('admin.operations.index', array_filter([
                                'hub_tab' => 'automation',
                                'automation_view' => $active === 'automation' ? 'pipeline' : null,
                            ])) }}"
                            @if(in_array($active, ['automation-health', 'automation'], true)) aria-current="page" @endif
                        >
                            Automation
                        </a>
                    </li>
                @endif
            @endcan

            @can('viewAny', App\Models\CashfreeWebhookLog::class)
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === 'webhook-explorer'])
                        href="{{ route('cashfree.webhook-explorer.index') }}"
                        @if($active === 'webhook-explorer') aria-current="page" @endif
                    >
                        Webhook Explorer
                    </a>
                </li>
            @endcan
        </ul>
    </nav>
@endif
