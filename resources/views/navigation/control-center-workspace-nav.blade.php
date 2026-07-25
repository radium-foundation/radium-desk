@props([
    'active' => 'operations',
])

@php
    use App\Models\LeaveRequest;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

    $tabs = [];

    if ($isAdminTeam && $user?->can('operations-dashboard.view')) {
        $tabs['operations'] = [
            'label' => 'Operations',
            'url' => route('admin.operations.index'),
        ];
    }

    if ($user?->can('workforce360.viewTeam')) {
        $tabs['workforce'] = [
            'label' => 'Workforce',
            'url' => route('workforce.index'),
        ];
    }

    if ($isAdminTeam && $user?->can('team-performance.view')) {
        $tabs['performance'] = [
            'label' => 'Performance',
            'url' => route('admin.workforce.performance.index'),
        ];
    }

    if ($isAdminTeam && Gate::check('viewAny', LeaveRequest::class)) {
        $tabs['leave'] = [
            'label' => 'Leave',
            'url' => route('leave-requests.index'),
        ];
    }
@endphp

@if(count($tabs) > 1)
    <nav class="workspace-nav control-center-workspace-nav mb-4" aria-label="Control Center workspace">
        <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
            @foreach($tabs as $key => $tab)
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === $key])
                        href="{{ $tab['url'] }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        {{ $tab['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
