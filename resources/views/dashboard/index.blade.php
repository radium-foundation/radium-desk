@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="app-content-compact"
         id="dashboard-page"
         data-workspace-context="dashboard"
         data-live-url="{{ route('dashboard.live') }}"
         data-live-filter="{{ $serviceCaseFilter ?? 'pending_admin' }}"
         data-live-mode="{{ $dashboardLiveMode ?? 'auto' }}"
         data-live-interval="{{ $dashboardPollIntervalMs ?? 30000 }}"
         data-user-id="{{ auth()->id() }}"
         data-reopen-quick-create="{{ ($reopenQuickCreate ?? false) ? 'true' : 'false' }}"
         @if($reverbConfigured ?? false)
         data-reverb-key="{{ config('broadcasting.connections.reverb.key') }}"
         data-reverb-host="{{ config('broadcasting.connections.reverb.options.host') }}"
         data-reverb-port="{{ config('broadcasting.connections.reverb.options.port') }}"
         data-reverb-scheme="{{ config('broadcasting.connections.reverb.options.scheme') }}"
         @endif>
        <div class="dashboard-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1 mb-1">
            <div>
                <h1 class="h4 mb-0">Dashboard</h1>
                <p class="text-muted small mb-0">Welcome back, {{ auth()->user()->firstName() }}.</p>
            </div>
            @if($canQuickCreate)
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#quickCreateModal">
                    <i class="bi bi-plus-circle me-1"></i> New Service Request
                </button>
            @endif
        </div>

        <div id="dashboard-kpi-strip" class="dashboard-kpi-strip-host mb-1">
            @include('dashboard.partials.kpi-strip', ['stats' => $stats])
        </div>

        @if($recentServiceCases->isNotEmpty() || auth()->user()?->can('incidents.view'))
            <div class="dashboard-primary-panel mb-1">
                @include('dashboard.partials.recent-service-cases', [
                    'recentServiceCases' => $recentServiceCases,
                    'serviceCaseFilter' => $serviceCaseFilter ?? 'pending_admin',
                    'canManageTransactions' => $canManageTransactions ?? false,
                    'canAssignDeviceModel' => $canAssignDeviceModel ?? false,
                    'canReassignServiceCases' => $canReassignServiceCases ?? false,
                ])
            </div>
        @endif

        @if($recentActivity->isNotEmpty())
            @include('dashboard.partials.recent-activity-feed', ['recentActivity' => $recentActivity])
        @endif

        @if(auth()->user()?->hasAnyRole([\Database\Seeders\RolePermissionSeeder::ROLE_ADMIN, \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN]))
            @include('dashboard.partials.admin-metrics-strip', ['stats' => $stats])
        @endif
    </div>

    @if($canQuickCreate)
        @include('dashboard.partials.quick-create-form', ['reopenQuickCreate' => $reopenQuickCreate ?? false])
    @endif

    @include('dashboard.partials.serial-number-modal')

    @if(auth()->user()?->can('incidents.update'))
        @include('dashboard.partials.device-model-modal', [
            'activeDeviceModels' => app(\App\Services\DeviceModelSettingsService::class)->activeOptions(),
        ])
    @endif
@endsection
