@extends('layouts.app')

@section('title', 'Leave Request')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('leave-requests.index') }}">Leave Requests</a></li>
                <li class="breadcrumb-item active" aria-current="page">Details</li>
            </ol>
        </nav>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <h1 class="h3 mb-1">{{ $leaveRequest->user?->name }}</h1>
                <p class="text-muted mb-0">
                    {{ display_app_date($leaveRequest->start_date) }}
                    – {{ display_app_date($leaveRequest->end_date) }}
                </p>
            </div>
            @include('leave-requests.partials.status-badge', ['status' => $leaveRequest->status])
        </div>
    </div>

    @if($operationalImpact)
        @include('leave-requests.partials.operational-impact', [
            'impact' => $operationalImpact,
            'leaveRequest' => $leaveRequest,
        ])
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Duration</dt>
                        <dd class="col-sm-9">{{ $leaveRequest->duration?->label() ?? 'Full Day' }}</dd>

                        <dt class="col-sm-3">Reason</dt>
                        <dd class="col-sm-9">{{ $leaveRequest->reason }}</dd>

                        <dt class="col-sm-3">Submitted</dt>
                        <dd class="col-sm-9">{{ display_app_datetime_24($leaveRequest->created_at) }}</dd>

                        @if($leaveRequest->reviewed_at)
                            <dt class="col-sm-3">Reviewed by</dt>
                            <dd class="col-sm-9">{{ $leaveRequest->reviewer?->name ?? '—' }}</dd>

                            <dt class="col-sm-3">Reviewed at</dt>
                            <dd class="col-sm-9">{{ display_app_datetime_24($leaveRequest->reviewed_at) }}</dd>
                        @endif

                        @if(filled($leaveRequest->review_notes))
                            <dt class="col-sm-3">Review notes</dt>
                            <dd class="col-sm-9">{{ $leaveRequest->review_notes }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            @can('review', $leaveRequest)
                @if($leaveRequest->status === \App\Enums\LeaveRequestStatus::Pending)
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h2 class="h6 mb-0">Review</h2>
                        </div>
                        <div class="card-body d-grid gap-3">
                            @if($operationalImpact)
                                <div @class([
                                    'alert mb-0 py-2 small',
                                    'alert-warning' => $operationalImpact->hasWorkload,
                                    'alert-success' => ! $operationalImpact->hasWorkload,
                                ])>
                                    {{ $operationalImpact->warningMessage }}
                                </div>
                            @endif

                            <form method="POST" action="{{ route('leave-requests.approve', $leaveRequest) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="approve_review_notes" class="form-label">Notes (required)</label>
                                    <textarea id="approve_review_notes" name="review_notes" rows="3" class="form-control" required>{{ old('review_notes') }}</textarea>
                                </div>
                                <button type="submit" class="btn btn-success w-100">Approve Leave</button>
                            </form>

                            <form method="POST" action="{{ route('leave-requests.reject', $leaveRequest) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="reject_review_notes" class="form-label">Rejection notes (required)</label>
                                    <textarea id="reject_review_notes" name="review_notes" rows="3" class="form-control" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-outline-danger w-100">Reject Leave</button>
                            </form>

                            @if($operationalImpact)
                                <div class="d-grid gap-2 pt-1">
                                    @if($operationalImpact->shortcuts['open_cases'] ?? null)
                                        <a href="{{ $operationalImpact->shortcuts['open_cases'] }}" class="btn btn-sm btn-outline-secondary">
                                            Open Assigned Cases
                                        </a>
                                    @endif
                                    @if($operationalImpact->shortcuts['appointments'] ?? null)
                                        <a href="{{ $operationalImpact->shortcuts['appointments'] }}" class="btn btn-sm btn-outline-secondary">
                                            Open Appointments
                                        </a>
                                    @endif
                                    @if($operationalImpact->shortcuts['ready_queue'] ?? null)
                                        <a href="{{ $operationalImpact->shortcuts['ready_queue'] }}" class="btn btn-sm btn-outline-secondary">
                                            Open Ready Queue
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
