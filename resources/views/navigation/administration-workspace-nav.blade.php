@props([
    'active' => 'overview',
])

@php
    use App\Models\CompanyHoliday;
    use App\Models\SettingProduct;
    use App\Models\SystemSetting;
    use App\Models\User;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

    $tabs = [];

    if ($isAdminTeam) {
        $tabs['overview'] = [
            'label' => 'Overview',
            'url' => route('admin.administration.index'),
        ];
    }

    if (Gate::check('viewAny', User::class)) {
        $tabs['users'] = [
            'label' => 'Users',
            'url' => route('users.index'),
        ];
    }

    if (Gate::check('system-settings.manage') || Gate::check('viewAny', SystemSetting::class)) {
        $tabs['system_settings'] = [
            'label' => 'System Settings',
            'url' => route('admin.system-settings.index'),
        ];
    }

    if ($user?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) && Gate::check('viewAny', SettingProduct::class)) {
        $tabs['application_settings'] = [
            'label' => 'Application Settings',
            'url' => route('settings.index'),
        ];
    }

    if (Gate::check('viewAny', CompanyHoliday::class)) {
        $tabs['holiday_calendar'] = [
            'label' => 'Holiday Calendar',
            'url' => route('admin.workforce.holidays.index'),
        ];
    }

    if (Gate::check('viewAny', SystemSetting::class)) {
        $tabs['integrations'] = [
            'label' => 'Integrations',
            'url' => route('admin.administration.index').'#administration-integrations',
        ];
    }
@endphp

@if(count($tabs) > 1)
    <nav class="workspace-nav administration-workspace-nav mb-4" aria-label="Administration workspace">
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
