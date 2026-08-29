@props([
    'active' => 'overview',
])

@php
    use App\Models\CompanyHoliday;
    use App\Models\SystemSetting;
    use App\Models\User;
    use App\Support\Administration\BackupAccess;
    use App\Support\Administration\PerformanceIntelligenceAccess;
    use App\Support\Administration\PlatformConfigurationAccess;
    use App\Support\IncomingEmail\IncomingEmailAccess;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;
    $canViewSettings = Gate::check('viewAny', SystemSetting::class)
        || $user?->can('system-settings.manage');
    $canManagePlatformConfiguration = PlatformConfigurationAccess::canManage($user);
    $canViewPerformanceIntelligence = PerformanceIntelligenceAccess::canView($user);
    $canViewBackups = BackupAccess::canView($user);
    $canViewLearningCenter = IncomingEmailAccess::allowsView($user);

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

    if ($isAdminTeam && Gate::check('viewAny', \App\Models\TodoCategory::class)) {
        $tabs['todo_categories'] = [
            'label' => 'To-Do Categories',
            'url' => route('todo-categories.index'),
        ];
    }

    if ($canViewSettings) {
        $tabs['operational_settings'] = [
            'label' => 'Operational Settings',
            'url' => route('admin.system-settings.index'),
        ];
    }

    if (
        $canViewLearningCenter
        && \Illuminate\Support\Facades\Route::has('admin.incoming-emails.index')
    ) {
        $tabs['learning_center'] = [
            'label' => 'Learning Center',
            'url' => route('admin.incoming-emails.index'),
        ];
    }

    if (
        \Illuminate\Support\Facades\Route::has('admin.ira-memory.index')
        && Gate::check('viewAny', \App\Models\IraMemory::class)
    ) {
        $tabs['ira_memory'] = [
            'label' => 'IRA Memory',
            'url' => route('admin.ira-memory.index'),
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

    if ($canViewBackups) {
        $tabs['backups'] = [
            'label' => 'Backups',
            'url' => route('admin.backups.index'),
        ];
    }

    if ($canViewPerformanceIntelligence) {
        $tabs['performance_intelligence'] = [
            'label' => 'Performance Intelligence',
            'url' => route('admin.performance-intelligence.index'),
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
