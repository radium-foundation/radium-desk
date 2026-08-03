@props([
    'active' => 'overview',
])

@php
    use App\Models\CompanyHoliday;
    use App\Models\SystemSetting;
    use App\Models\User;
    use App\Support\Administration\PlatformConfigurationAccess;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;
    $canViewSettings = Gate::check('viewAny', SystemSetting::class)
        || $user?->can('system-settings.manage');
    $canManagePlatformConfiguration = PlatformConfigurationAccess::canManage($user);

    $tabs = [];

    if ($isAdminTeam) {
        $tabs['overview'] = [
            'label' => 'Overview',
            'url' => route('admin.administration.index'),
        ];
    }

    if (Gate::check('viewAny', User::class)) {
        $tabs['users_roles'] = [
            'label' => 'Users & Roles',
            'url' => route('users.index'),
        ];
    }

    if ($canViewSettings) {
        $tabs['operational_settings'] = [
            'label' => 'Operational Settings',
            'url' => route('admin.system-settings.index'),
        ];
    }

    if (Gate::check('viewAny', CompanyHoliday::class)) {
        $tabs['holiday_calendar'] = [
            'label' => 'Holiday Calendar',
            'url' => route('admin.workforce.holidays.index'),
        ];
    }

    if ($canManagePlatformConfiguration) {
        $tabs['platform_configuration'] = [
            'label' => 'Platform Configuration',
            'url' => route('admin.platform-configuration.index'),
        ];
    }
@endphp

@if(count($tabs) > 1)
    <nav class="workspace-nav administration-workspace-nav mb-4" aria-label="Administration workspace">
        <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
            @foreach($tabs as $key => $tab)
                <li class="nav-item" role="presentation">
                    <a
                        @class(['nav-link', 'active' => $active === $key || ($active === 'settings' && $key === 'operational_settings')])
                        href="{{ $tab['url'] }}"
                        @if($active === $key || ($active === 'settings' && $key === 'operational_settings')) aria-current="page" @endif
                    >
                        {{ $tab['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
