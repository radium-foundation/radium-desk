@extends('layouts.app')

@section('title', 'Audit Logs')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Audit Logs</h1>
        <p class="text-muted mb-0">Review system activity and record changes.</p>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Search</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('audit-logs.index') }}" class="row g-3">
                <div class="col-md-8 col-lg-9">
                    <label for="filter_q" class="form-label">Search</label>
                    <input type="search" name="q" id="filter_q" class="form-control"
                           value="{{ $filters['q'] ?? '' }}"
                           placeholder="Search by user name, event, or record ID">
                </div>
                <div class="col-md-4 col-lg-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Filters</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('audit-logs.index') }}" class="row g-3">
                @if(! empty($filters['q']))
                    <input type="hidden" name="q" value="{{ $filters['q'] }}">
                @endif

                <div class="col-md-6 col-lg-4">
                    <label for="filter_user_id" class="form-label">User</label>
                    <select name="user_id" id="filter_user_id" class="form-select">
                        <option value="">All users</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_event" class="form-label">Event Type</label>
                    <select name="event" id="filter_event" class="form-select">
                        <option value="">All events</option>
                        @foreach($events as $event)
                            <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>
                                {{ str_replace('_', ' ', $event) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_module" class="form-label">Module</label>
                    <select name="module" id="filter_module" class="form-select">
                        <option value="">All modules</option>
                        @foreach($modules as $module)
                            <option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>
                                {{ $module }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_date_from" class="form-label">Date From</label>
                    <input type="date" name="date_from" id="filter_date_from" class="form-control"
                           value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_date_to" class="form-label">Date To</label>
                    <input type="date" name="date_to" id="filter_date_to" class="form-control"
                           value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i> Apply Filters
                    </button>
                    <a href="{{ route('audit-logs.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($auditLogs->isEmpty())
                <div class="p-4 text-center text-muted">
                    No audit log entries found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Event</th>
                                <th>Module</th>
                                <th>Record ID</th>
                                <th>IP Address</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($auditLogs as $auditLog)
                                <tr>
                                    <td class="text-nowrap">{{ display_app_datetime_seconds($auditLog->created_at) }}</td>
                                    <td>{{ $auditLog->user?->name ?? 'System' }}</td>
                                    <td>@include('audit-logs.partials.event-badge', ['auditLog' => $auditLog])</td>
                                    <td>{{ class_basename($auditLog->auditable_type) }}</td>
                                    <td>
                                        @if($auditLog->auditable_id)
                                            <span class="font-monospace">#{{ $auditLog->auditable_id }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-nowrap">{{ $auditLog->ip_address ?: '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('audit-logs.show', $auditLog) }}" class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($auditLogs->hasPages())
            <div class="card-footer bg-white">
                {{ $auditLogs->links() }}
            </div>
        @endif
    </div>
@endsection
