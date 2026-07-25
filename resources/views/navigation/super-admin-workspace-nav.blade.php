@props([
    'active' => 'mission_control',
])

@php
    use App\Models\AuditLog;
    use App\Models\CashfreeWebhookLog;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();

    $tabs = [];

    if ($user?->can('platform-dashboard.view')) {
        $tabs['mission_control'] = [
            'label' => 'Mission Control',
            'url' => route('admin.platform.index'),
        ];
    }

    if ($user?->can('operations-dashboard.view')) {
        $tabs['control_center'] = [
            'label' => 'Control Center',
            'url' => route('admin.operations.index'),
        ];
    }

    if ($user?->can('automation-operations.view')) {
        $tabs['automation'] = [
            'label' => 'Automation',
            'url' => route('admin.operations.index', ['hub_tab' => 'automation']),
        ];
    }

    if (Gate::check('viewAny', CashfreeWebhookLog::class)) {
        $tabs['webhook_explorer'] = [
            'label' => 'Webhook Explorer',
            'url' => route('cashfree.webhook-explorer.index'),
        ];
    }

    if (Gate::check('viewAny', AuditLog::class)) {
        $tabs['audit'] = [
            'label' => 'Audit',
            'url' => route('audit-logs.index'),
        ];
    }

    if ($user?->can('system-settings.manage')) {
        $tabs['system_settings'] = [
            'label' => 'System Settings',
            'url' => route('admin.system-settings.index'),
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
    <nav class="workspace-nav super-admin-workspace-nav mb-4" aria-label="Super Admin workspace">
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
