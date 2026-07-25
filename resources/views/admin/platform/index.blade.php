@extends('layouts.app')

@section('title', 'Mission Control')

@section('content')
    <div
        id="platform-dashboard-root"
        data-platform-dashboard
        data-poll-interval-seconds="{{ $platformPollIntervalSeconds ?? 60 }}"
        data-generated-at="{{ $dashboard->generatedAt->toIso8601String() }}"
    >
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
            <div>
                <h1 class="h3 mb-1">Command Center</h1>
                <p class="text-muted mb-0">Single platform workspace for health, automation, audit, finance, and system controls.</p>
            </div>
            <div class="text-muted small" data-platform-dashboard-generated-at>
                Snapshot {{ \App\Support\AppDateFormatter::format($dashboard->generatedAt, 'H:i') }}
            </div>
        </div>

        @include('navigation.super-admin-workspace-nav', ['active' => 'mission_control'])

        @php
            $user = auth()->user();
            $canViewAutomation = $user?->can('automation-operations.view');
            $canViewWebhooks = Gate::check('viewAny', App\Models\CashfreeWebhookLog::class);
            $canViewAudit = Gate::check('viewAny', App\Models\AuditLog::class);
            $canViewOps = $user?->can('operations-dashboard.view');
            $canManageSystemSettings = $user?->can('system-settings.manage');
        @endphp

        <div class="row g-3 mb-4" aria-label="Platform workspace shortcuts">
            @if($canViewOps)
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('admin.operations.index'),
                        'icon' => 'bi-sliders',
                        'title' => 'Control Center',
                        'description' => 'Live operational command center.',
                    ])
                </div>
            @endif
            @if($canViewAutomation)
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('admin.operations.index', ['hub_tab' => 'automation']),
                        'icon' => 'bi-robot',
                        'title' => 'Automation',
                        'description' => 'Health and pipeline operations.',
                    ])
                </div>
            @endif
            @if($canViewWebhooks)
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('cashfree.webhook-explorer.index'),
                        'icon' => 'bi-broadcast',
                        'title' => 'Webhook Explorer',
                        'description' => 'Inspect Cashfree webhook payloads.',
                    ])
                </div>
            @endif
            @if($canViewAudit)
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('audit-logs.index'),
                        'icon' => 'bi-journal-text',
                        'title' => 'Audit',
                        'description' => 'Review system activity and changes.',
                    ])
                </div>
            @endif
            @if($canManageSystemSettings)
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('admin.system-settings.index'),
                        'icon' => 'bi-toggles',
                        'title' => 'System Settings',
                        'description' => 'Realtime, flags, and integrations.',
                    ])
                </div>
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('admin.system-settings.index').'#realtime-settings-card',
                        'icon' => 'bi-reception-4',
                        'title' => 'Realtime',
                        'description' => 'Broadcast provider and connection health.',
                    ])
                </div>
            @endif
            <div class="col-md-6 col-lg-3">
                @include('admin.hubs.partials.link-card', [
                    'href' => route('admin.platform.index').'#platform-health',
                    'icon' => 'bi-heart-pulse',
                    'title' => 'Platform Health',
                    'description' => 'Queues, cache, database, and scheduler.',
                ])
            </div>
            @if($canManageSystemSettings)
                <div class="col-md-6 col-lg-3">
                    @include('admin.hubs.partials.link-card', [
                        'href' => route('admin.administration.index').'#administration-integrations',
                        'icon' => 'bi-plug',
                        'title' => 'Integrations',
                        'description' => 'Administration integrations hub.',
                    ])
                </div>
            @endif
        </div>

        @forelse($dashboard->sections as $section)
            @include('admin.platform.partials.section', ['section' => $section])
        @empty
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-0">No platform cards are registered yet.</p>
                </div>
            </div>
        @endforelse
    </div>
@endsection
