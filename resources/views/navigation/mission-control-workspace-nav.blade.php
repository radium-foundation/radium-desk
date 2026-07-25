@props([
    'active' => 'overview',
])

@php
    use App\Models\AuditLog;
    use App\Models\CashfreeWebhookLog;
    use App\Models\LeaveRequest;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();
    $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

    $tabs = [];

    if ($user?->can('platform-dashboard.view')) {
        $tabs['overview'] = [
            'label' => 'Overview',
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

    if ($isAdminTeam && Gate::check('viewAny', LeaveRequest::class)) {
        $tabs['leave'] = [
            'label' => 'Leave',
            'url' => route('leave-requests.index'),
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
@endphp

@if(count($tabs) > 1)
    <nav class="workspace-nav mission-control-workspace-nav mb-4" aria-label="Mission Control workspace">
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
