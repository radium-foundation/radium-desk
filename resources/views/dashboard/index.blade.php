@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="app-content-compact"
         id="dashboard-page"
         data-workspace-context="dashboard"
         data-live-url="{{ route('dashboard.live') }}"
         data-live-filter="{{ $serviceCaseFilter ?? 'pending_admin' }}"
         data-live-interval="30000">
        <div class="dashboard-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1 mb-2">
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

        <div id="dashboard-kpi-strip" class="mb-2">
            @include('dashboard.partials.kpi-strip', ['stats' => $stats])
        </div>

        @if($recentServiceCases->isNotEmpty() || auth()->user()?->can('incidents.view'))
            <div class="mb-2">
                @include('dashboard.partials.recent-service-cases', [
                    'recentServiceCases' => $recentServiceCases,
                    'serviceCaseFilter' => $serviceCaseFilter ?? 'pending_admin',
                    'canManageTransactions' => $canManageTransactions ?? false,
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
        @include('dashboard.partials.quick-create-form')
    @endif
@endsection
