@props(['amendment', 'compact' => false])

@php
    $leaveRequest = $amendment->leaveRequest;
@endphp

<div @class(['border rounded p-3', 'bg-light' => ! $compact])>
    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
        <div>
            <div class="fw-semibold">{{ $leaveRequest?->user?->name ?? 'Team member' }}</div>
            <div class="text-muted small">
                {{ $amendment->type->label() }} request
                · {{ display_app_date($amendment->previous_start_date) }} – {{ display_app_date($amendment->previous_end_date) }}
                @if($amendment->type === \App\Enums\LeaveAmendmentType::DateChange)
                    → {{ display_app_date($amendment->proposed_start_date) }} – {{ display_app_date($amendment->proposed_end_date) }}
                @endif
            </div>
            <div class="small mt-1">{{ $amendment->reason }}</div>
        </div>
        @include('leave-requests.partials.amendment-status-badge', ['amendment' => $amendment])
    </div>

    @can('review', $amendment)
        <div class="d-grid gap-2">
            <form method="POST" action="{{ route('leave-request-amendments.approve', $amendment) }}">
                @csrf
                <input type="hidden" name="return_to" value="index">
                <div class="mb-2">
                    <label class="form-label small mb-1" for="approve_amendment_notes_{{ $amendment->id }}">Approval notes (required)</label>
                    <textarea id="approve_amendment_notes_{{ $amendment->id }}" name="review_notes" rows="2" class="form-control form-control-sm" required></textarea>
                </div>
                <button type="submit" class="btn btn-sm btn-success">Approve Amendment</button>
            </form>

            <form method="POST" action="{{ route('leave-request-amendments.reject', $amendment) }}">
                @csrf
                <input type="hidden" name="return_to" value="index">
                <div class="mb-2">
                    <label class="form-label small mb-1" for="reject_amendment_notes_{{ $amendment->id }}">Rejection notes (required)</label>
                    <textarea id="reject_amendment_notes_{{ $amendment->id }}" name="review_notes" rows="2" class="form-control form-control-sm" required></textarea>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-danger">Reject Amendment</button>
            </form>

            <a href="{{ route('leave-requests.show', $leaveRequest) }}" class="btn btn-sm btn-outline-primary">View Leave</a>
        </div>
    @endcan
</div>
