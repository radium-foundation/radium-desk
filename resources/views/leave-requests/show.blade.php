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

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <dl class="row mb-0">
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
                            <form method="POST" action="{{ route('leave-requests.approve', $leaveRequest) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="approve_review_notes" class="form-label">Notes (optional)</label>
                                    <textarea id="approve_review_notes" name="review_notes" rows="3" class="form-control">{{ old('review_notes') }}</textarea>
                                </div>
                                <button type="submit" class="btn btn-success w-100">Approve</button>
                            </form>

                            <form method="POST" action="{{ route('leave-requests.reject', $leaveRequest) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="reject_review_notes" class="form-label">Rejection notes (optional)</label>
                                    <textarea id="reject_review_notes" name="review_notes" rows="3" class="form-control"></textarea>
                                </div>
                                <button type="submit" class="btn btn-outline-danger w-100">Reject</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
