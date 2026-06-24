@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="app-content-compact">
        <div class="dashboard-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
            <div>
                <h1 class="h4 mb-0">Dashboard</h1>
                <p class="text-muted small mb-0">Welcome back, {{ auth()->user()->firstName() }}.</p>
            </div>
            @if($canQuickCreate)
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quickCreateModal">
                    <i class="bi bi-plus-circle me-1"></i> New Service Request
                </button>
            @endif
        </div>

        @include('dashboard.partials.action-stats', ['stats' => $stats])

        @if($recentServiceCases->isNotEmpty() || auth()->user()?->can('incidents.view'))
            <div class="mb-3">
                @include('dashboard.partials.recent-service-cases', [
                    'recentServiceCases' => $recentServiceCases,
                    'serviceCaseFilter' => $serviceCaseFilter ?? 'pending_admin',
                    'canManageTransactions' => $canManageTransactions ?? false,
                ])
            </div>
        @endif

        @if(auth()->user()?->hasAnyRole([\Database\Seeders\RolePermissionSeeder::ROLE_ADMIN, \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN]))
            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-xl-3">
                    @include('dashboard.partials.stat-card', [
                        'label' => 'Total Orders',
                        'value' => $stats['total_orders'],
                        'icon' => 'bi-box-seam',
                        'color' => 'secondary',
                    ])
                </div>
                <div class="col-sm-6 col-xl-3">
                    @include('dashboard.partials.stat-card', [
                        'label' => config('ui.service_case.resolved_label'),
                        'value' => $stats['resolved_incidents'],
                        'icon' => 'bi-check-circle',
                        'color' => 'success',
                    ])
                </div>
                <div class="col-sm-6 col-xl-3">
                    @include('dashboard.partials.stat-card', [
                        'label' => config('ui.service_case.closed_label'),
                        'value' => $stats['closed_incidents'],
                        'icon' => 'bi-archive',
                        'color' => 'secondary',
                    ])
                </div>

                @isset($stats['approved_refunds'])
                    <div class="col-sm-6 col-xl-3">
                        @include('dashboard.partials.stat-card', [
                            'label' => 'Approved Refunds',
                            'value' => $stats['approved_refunds'],
                            'icon' => 'bi-check-circle',
                            'color' => 'success',
                        ])
                    </div>
                @endisset

                @isset($stats['rejected_refunds'])
                    <div class="col-sm-6 col-xl-3">
                        @include('dashboard.partials.stat-card', [
                            'label' => 'Rejected Refunds',
                            'value' => $stats['rejected_refunds'],
                            'icon' => 'bi-x-circle',
                            'color' => 'danger',
                        ])
                    </div>
                @endisset

                @isset($stats['approval_numbers'])
                    <div class="col-sm-6 col-xl-3">
                        @include('dashboard.partials.stat-card', [
                            'label' => 'Approval Numbers',
                            'value' => $stats['approval_numbers'],
                            'icon' => 'bi-check2-square',
                            'color' => 'info',
                        ])
                    </div>
                @endisset

                @isset($stats['total_users'])
                    <div class="col-sm-6 col-xl-3">
                        @include('dashboard.partials.stat-card', [
                            'label' => 'Total Users',
                            'value' => $stats['total_users'],
                            'icon' => 'bi-people',
                            'color' => 'info',
                        ])
                    </div>
                @endisset

                @isset($stats['audit_log_count'])
                    <div class="col-sm-6 col-xl-3">
                        @include('dashboard.partials.stat-card', [
                            'label' => 'Audit Log Entries',
                            'value' => $stats['audit_log_count'],
                            'icon' => 'bi-journal-text',
                            'color' => 'secondary',
                        ])
                    </div>
                @endisset
            </div>
        @endif

        @if($recentActivity->isNotEmpty())
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-2">
                    <h2 class="h6 mb-0">Recent Activity</h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>User</th>
                                    <th>Event</th>
                                    <th>Record</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentActivity as $log)
                                    <tr>
                                        <td class="text-nowrap small">{{ $log->created_at?->format('d M Y, h:i A') ?: '—' }}</td>
                                        <td class="small">{{ $log->user?->name ?? 'System' }}</td>
                                        <td>
                                            <span class="badge text-bg-light text-dark border text-capitalize">
                                                {{ str_replace('_', ' ', $log->event) }}
                                            </span>
                                        </td>
                                        <td class="small">
                                            {{ class_basename($log->auditable_type) }}
                                            @if($log->auditable_id)
                                                <span class="text-muted">#{{ $log->auditable_id }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if($canQuickCreate)
        @include('dashboard.partials.quick-create-form')
    @endif
@endsection
