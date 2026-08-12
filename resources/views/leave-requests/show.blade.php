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
            <div class="d-flex flex-wrap gap-2 align-items-center">
                @include('leave-requests.partials.status-badge', ['status' => $leaveRequest->status])
                @if($leaveRequest->pendingAmendment)
                    @include('leave-requests.partials.amendment-status-badge', ['amendment' => $leaveRequest->pendingAmendment])
                @endif
            </div>
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
            <div class="card border-0 shadow-sm mb-4">
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

            @if($leaveRequest->amendments->isNotEmpty())
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Amendment History</h2>
                    </div>
                    <div class="card-body d-grid gap-3">
                        @foreach($leaveRequest->amendments->sortByDesc('created_at') as $amendment)
                            <div class="border rounded p-3">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <div class="fw-semibold">{{ $amendment->type->label() }}</div>
                                    @include('leave-requests.partials.amendment-status-badge', ['amendment' => $amendment])
                                </div>
                                <div class="small text-muted">
                                    Requested by {{ $amendment->requester?->name ?? '—' }}
                                    · {{ display_app_datetime_24($amendment->created_at) }}
                                </div>
                                <div class="small mt-2">
                                    Original: {{ display_app_date($amendment->previous_start_date) }} – {{ display_app_date($amendment->previous_end_date) }}
                                    @if($amendment->type === \App\Enums\LeaveAmendmentType::DateChange)
                                        <br>Proposed: {{ display_app_date($amendment->proposed_start_date) }} – {{ display_app_date($amendment->proposed_end_date) }}
                                    @endif
                                </div>
                                <div class="mt-2">{{ $amendment->reason }}</div>
                                @if($amendment->reviewed_at)
                                    <div class="small text-muted mt-2">
                                        Reviewed by {{ $amendment->reviewer?->name ?? '—' }}
                                        · {{ display_app_datetime_24($amendment->reviewed_at) }}
                                        @if(filled($amendment->review_notes))
                                            <br>{{ $amendment->review_notes }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4 d-grid gap-3">
            @can('update', $leaveRequest)
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <a href="{{ route('leave-requests.edit', $leaveRequest) }}" class="btn btn-outline-primary w-100">
                            Edit Pending Request
                        </a>
                    </div>
                </div>
            @endcan

            @can('requestAmendment', $leaveRequest)
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Request Change</h2>
                    </div>
                    <div class="card-body d-grid gap-3">
                        <form method="POST" action="{{ route('leave-requests.amendments.store', $leaveRequest) }}">
                            @csrf
                            <input type="hidden" name="type" value="date_change">
                            <div class="mb-2">
                                <label class="form-label">New start date</label>
                                <input type="date" name="proposed_start_date" class="form-control" value="{{ old('proposed_start_date', $leaveRequest->start_date?->toDateString()) }}" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">New end date</label>
                                <input type="date" name="proposed_end_date" class="form-control" value="{{ old('proposed_end_date', $leaveRequest->end_date?->toDateString()) }}" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Duration</label>
                                <select name="proposed_duration" class="form-select">
                                    <option value="full_day" @selected(old('proposed_duration', $leaveRequest->duration?->value) === 'full_day')>Full Day</option>
                                    <option value="half_day" @selected(old('proposed_duration', $leaveRequest->duration?->value) === 'half_day')>Half Day</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Reason</label>
                                <textarea name="reason" rows="2" class="form-control" required>{{ old('reason') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-primary w-100">Request Date Change</button>
                        </form>

                        <form method="POST" action="{{ route('leave-requests.amendments.store', $leaveRequest) }}">
                            @csrf
                            <input type="hidden" name="type" value="cancellation">
                            <div class="mb-2">
                                <label class="form-label">Cancellation reason</label>
                                <textarea name="reason" rows="2" class="form-control" required>{{ old('reason') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-danger w-100">Request Cancellation</button>
                        </form>
                    </div>
                </div>
            @endcan

            @if($leaveRequest->pendingAmendment && auth()->user()?->can('review', $leaveRequest->pendingAmendment))
                @include('leave-requests.partials.pending-amendment-row', [
                    'amendment' => $leaveRequest->pendingAmendment,
                    'compact' => true,
                ])
            @endif

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
                        </div>
                    </div>
                @endif
            @endcan

            @can('manage', $leaveRequest)
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Manage Leave</h2>
                    </div>
                    <div class="card-body d-grid gap-3">
                        <form method="POST" action="{{ route('leave-requests.manage-update', $leaveRequest) }}">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label">Start date</label>
                                <input type="date" name="proposed_start_date" class="form-control" value="{{ $leaveRequest->start_date?->toDateString() }}" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">End date</label>
                                <input type="date" name="proposed_end_date" class="form-control" value="{{ $leaveRequest->end_date?->toDateString() }}" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Duration</label>
                                <select name="proposed_duration" class="form-select">
                                    <option value="full_day" @selected($leaveRequest->duration?->value === 'full_day')>Full Day</option>
                                    <option value="half_day" @selected($leaveRequest->duration?->value === 'half_day')>Half Day</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Reason</label>
                                <textarea name="reason" rows="2" class="form-control" required></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Review notes</label>
                                <textarea name="review_notes" rows="2" class="form-control" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Update Leave</button>
                        </form>

                        <form method="POST" action="{{ route('leave-requests.manage-cancel', $leaveRequest) }}">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label">Cancellation reason</label>
                                <textarea name="reason" rows="2" class="form-control" required></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Review notes</label>
                                <textarea name="review_notes" rows="2" class="form-control" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-danger w-100">Cancel Leave</button>
                        </form>
                    </div>
                </div>
            @endcan
        </div>
    </div>
@endsection
