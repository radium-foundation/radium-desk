@php
    /** @var \App\Models\LeaveRequest $leaveRequest */
    /** @var \App\Services\Operations\LeaveRequestService $leaveRequestService */
    $employeeName = $leaveRequest->user?->firstName() ?: ($leaveRequest->user?->name ?? 'Team member');
    $datesLabel = $leaveRequestService->leaveDatesLabel($leaveRequest);
    $ageLabel = $leaveRequestService->pendingAgeLabel($leaveRequest);
    $reasonPreview = \Illuminate\Support\Str::limit(trim((string) $leaveRequest->reason), 80);
@endphp

<div class="border rounded-3 p-3" id="leave-pending-{{ $leaveRequest->id }}">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div class="flex-grow-1">
            <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                <span class="fw-semibold">{{ $employeeName }}</span>
                <span class="text-muted">{{ $datesLabel }}</span>
                <span class="badge text-bg-light border">{{ $leaveRequest->duration?->label() ?? 'Full Day' }}</span>
            </div>
            <div class="small text-muted mb-1">{{ $ageLabel }}</div>
            @if($reasonPreview !== '')
                <div class="small">{{ $reasonPreview }}</div>
            @endif
        </div>

        <div class="d-grid gap-2" style="min-width: min(100%, 18rem);">
            <form method="POST" action="{{ route('leave-requests.approve', $leaveRequest) }}" class="d-grid gap-2">
                @csrf
                <input type="hidden" name="return_to" value="index">
                <label class="visually-hidden" for="approve_notes_{{ $leaveRequest->id }}">Approve notes</label>
                <input
                    id="approve_notes_{{ $leaveRequest->id }}"
                    type="text"
                    name="review_notes"
                    class="form-control form-control-sm"
                    placeholder="Approval note (required)"
                    required
                >
                <button type="submit" class="btn btn-sm btn-success">Approve</button>
            </form>

            <form method="POST" action="{{ route('leave-requests.reject', $leaveRequest) }}" class="d-grid gap-2">
                @csrf
                <input type="hidden" name="return_to" value="index">
                <label class="visually-hidden" for="reject_notes_{{ $leaveRequest->id }}">Reject notes</label>
                <input
                    id="reject_notes_{{ $leaveRequest->id }}"
                    type="text"
                    name="review_notes"
                    class="form-control form-control-sm"
                    placeholder="Rejection note (required)"
                    required
                >
                <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
            </form>
        </div>
    </div>
</div>
