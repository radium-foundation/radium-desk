@extends('layouts.app')

@section('title', 'Leave Requests')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Leave Requests</h1>
            <p class="text-muted mb-0">Submit and track team leave for assignment planning.</p>
        </div>
        @can('create', \App\Models\LeaveRequest::class)
            <a href="{{ route('leave-requests.create') }}" class="btn btn-primary">
                <i class="bi bi-calendar-plus me-1"></i> Request Leave
            </a>
        @endcan
    </div>

    @include('workforce.partials.hub-nav', ['active' => 'leave'])

    @if($canReviewLeave && ($pendingToday->isNotEmpty() || $pendingUpcoming->isNotEmpty()))
        <div class="card border-0 shadow-sm mb-4 border-warning-subtle">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Pending Leave Approvals</h2>
                <span class="badge text-bg-warning">{{ $pendingToday->count() + $pendingUpcoming->count() }}</span>
            </div>
            <div class="card-body">
                @if($pendingToday->isNotEmpty())
                    <h3 class="h6 text-muted text-uppercase small mb-3">Today</h3>
                    <div class="d-grid gap-3 mb-4">
                        @foreach($pendingToday as $leaveRequest)
                            @include('leave-requests.partials.pending-approval-row', [
                                'leaveRequest' => $leaveRequest,
                                'leaveRequestService' => $leaveRequestService,
                                'compact' => false,
                            ])
                        @endforeach
                    </div>
                @endif

                @if($pendingUpcoming->isNotEmpty())
                    <h3 class="h6 text-muted text-uppercase small mb-3">Upcoming</h3>
                    <div class="d-grid gap-3">
                        @foreach($pendingUpcoming as $leaveRequest)
                            @include('leave-requests.partials.pending-approval-row', [
                                'leaveRequest' => $leaveRequest,
                                'leaveRequestService' => $leaveRequestService,
                                'compact' => false,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if(($canManageLeave ?? false) && ($pendingAmendments ?? collect())->isNotEmpty())
        <div class="card border-0 shadow-sm mb-4 border-info-subtle">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Pending Leave Amendments</h2>
                <span class="badge text-bg-warning">{{ $pendingAmendments->count() }}</span>
            </div>
            <div class="card-body d-grid gap-3">
                @foreach($pendingAmendments as $amendment)
                    @include('leave-requests.partials.pending-amendment-row', [
                        'amendment' => $amendment,
                        'compact' => false,
                    ])
                @endforeach
            </div>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('leave-requests.index') }}" class="row g-3">
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach(\App\Enums\LeaveRequestStatus::cases() as $statusOption)
                            <option value="{{ $statusOption->value }}" @selected(($filters['status'] ?? '') === $statusOption->value)>
                                {{ $statusOption->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('leave-requests.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Team Member</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($leaveRequests as $leaveRequest)
                        <tr>
                            <td>{{ $leaveRequest->user?->name }}</td>
                            <td>
                                {{ display_app_date($leaveRequest->start_date) }}
                                – {{ display_app_date($leaveRequest->end_date) }}
                            </td>
                            <td>
                                @include('leave-requests.partials.status-badge', ['status' => $leaveRequest->status])
                                @if($leaveRequest->pendingAmendment)
                                    <div class="mt-1">
                                        @include('leave-requests.partials.amendment-status-badge', ['amendment' => $leaveRequest->pendingAmendment])
                                    </div>
                                @endif
                            </td>
                            <td>{{ display_app_datetime_24($leaveRequest->created_at) }}</td>
                            <td class="text-end">
                                <a href="{{ route('leave-requests.show', $leaveRequest) }}" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center py-4">No leave requests found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($leaveRequests->hasPages())
            <div class="card-footer bg-white">
                {{ $leaveRequests->links() }}
            </div>
        @endif
    </div>
@endsection
