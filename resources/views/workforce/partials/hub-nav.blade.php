@props([
    'active' => 'team',
])

@php
    use Database\Seeders\RolePermissionSeeder;

    $user = auth()->user();
    $showsWorkforceHubNav = $user?->hasAnyRole([
        RolePermissionSeeder::ROLE_ADMIN,
        RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        RolePermissionSeeder::ROLE_SUPERADMIN,
    ]) && $user->can('workforce360.viewTeam');
@endphp

@if($showsWorkforceHubNav)
    <nav class="workforce360-hub-nav mb-4" aria-label="Workforce hub">
        <ul class="nav nav-tabs workforce360-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a
                    @class(['nav-link', 'active' => $active === 'team'])
                    href="{{ route('workforce.index') }}"
                    @if($active === 'team') aria-current="page" @endif
                >
                    Team
                </a>
            </li>

            @can('team-performance.view')
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === 'performance'])
                        href="{{ route('admin.workforce.performance.index') }}"
                        @if($active === 'performance') aria-current="page" @endif
                    >
                        Performance
                    </a>
                </li>
            @endcan

            @can('viewAny', App\Models\LeaveRequest::class)
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === 'leave'])
                        href="{{ route('leave-requests.index') }}"
                        @if($active === 'leave') aria-current="page" @endif
                    >
                        Leave
                    </a>
                </li>
            @endcan

            @can('viewAny', App\Models\CompanyHoliday::class)
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === 'holidays'])
                        href="{{ route('admin.workforce.holidays.index') }}"
                        @if($active === 'holidays') aria-current="page" @endif
                    >
                        Holidays
                    </a>
                </li>
            @endcan
        </ul>
    </nav>
@endif
