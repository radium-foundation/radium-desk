@props([
    'active' => 'overview',
])

@php
    use App\Models\AuditLog;
    use App\Models\CashfreeWebhookLog;
    use App\Models\CompanyHoliday;
    use App\Models\LeaveRequest;
    use App\Models\SystemSetting;
    use App\Models\User;
    use App\Support\Administration\BackupAccess;
    use App\Support\Administration\PerformanceIntelligenceAccess;
    use App\Support\Administration\PlatformConfigurationAccess;
    use App\Support\IncomingEmail\IncomingEmailAccess;
    use App\Support\Workforce\AttendanceManagementAccess;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

    $tabs = [];

    if ($isAdminTeam && AttendanceManagementAccess::allows($user)) {
        $tabs['attendance'] = [
            'label' => 'Attendance',
            'url' => route('workforce-management.attendance.index'),
        ];
    }

    if (Gate::check('viewAny', LeaveRequest::class)) {
        $tabs['leave'] = [
            'label' => 'Leave',
            'url' => route('leave-requests.index'),
        ];
    }

    if ($user?->can('platform-dashboard.view')) {
        $tabs['overview'] = [
            'label' => 'Control Center',
            'url' => route('admin.platform.index'),
        ];
    }

    if ($user?->can('operations-dashboard.view')) {
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

    if ($user?->can('automation-operations.view')) {
        $tabs['automation'] = [
            'label' => 'Automation',
            'url' => route('admin.operations.index', ['hub_tab' => 'automation']),
        ];
    }

    if (Gate::check('viewAny', AuditLog::class)) {
        $tabs['audit_logs'] = [
            'label' => 'Audit Logs',
            'url' => route('audit-logs.index'),
        ];
    }

    if (Gate::check('viewAny', CashfreeWebhookLog::class)) {
        $tabs['webhook_explorer'] = [
            'label' => 'Webhook Explorer',
            'url' => route('cashfree.webhook-explorer.index'),
        ];
    }

    if ($user?->can('platform-dashboard.view')) {
        $tabs['platform_health'] = [
            'label' => 'Platform Health',
            'url' => route('admin.platform.index').'#platform-health',
        ];
    }

    if ($isAdminTeam) {
        $tabs['administration'] = [
            'label' => 'Administration',
            'url' => route('admin.administration.index'),
        ];
    }

    if (Gate::check('viewAny', User::class)) {
        $tabs['users_roles'] = [
            'label' => 'Users & Roles',
            'url' => route('users.index'),
        ];
    }

    $canViewSettings = Gate::check('viewAny', SystemSetting::class)
        || $user?->can('system-settings.manage');

    if ($canViewSettings) {
        $tabs['operational_settings'] = [
            'label' => 'Operational Settings',
            'url' => route('admin.system-settings.index'),
        ];
    }

    if (
        IncomingEmailAccess::allowsView($user)
        && \Illuminate\Support\Facades\Route::has('admin.incoming-emails.index')
    ) {
        $tabs['incoming_email'] = [
            'label' => 'Incoming Email',
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

    if (PlatformConfigurationAccess::canManage($user)) {
        $tabs['platform_configuration'] = [
            'label' => 'Platform Configuration',
            'url' => route('admin.platform-configuration.index'),
        ];
    }

    if (BackupAccess::canView($user)) {
        $tabs['backups'] = [
            'label' => 'Backups',
            'url' => route('admin.backups.index'),
        ];
    }

    if (PerformanceIntelligenceAccess::canView($user)) {
        $tabs['performance_intelligence'] = [
            'label' => 'Performance Intelligence',
            'url' => route('admin.performance-intelligence.index'),
        ];
    }

    if ($isAdminTeam && config('workforce_recognition.enabled') && $user?->can('workforce.recognition.view')) {
        $tabs['recognition'] = [
            'label' => 'Work Recognition',
            'url' => route('workforce-management.recognition.index'),
        ];
    }
@endphp

@if(count($tabs) > 0)
    <nav class="workspace-nav control-and-admin-workspace-nav mb-4" aria-label="{{ __('Control & Admin workspace') }}">
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
