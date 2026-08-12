<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewLeaveAmendmentRequest;
use App\Http\Requests\StoreLeaveAmendmentRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAmendment;
use App\Services\Operations\LeaveRequestAmendmentService;
use Illuminate\Http\RedirectResponse;

class LeaveRequestAmendmentController extends Controller
{
    public function __construct(
        private readonly LeaveRequestAmendmentService $amendmentService,
    ) {}

    public function store(StoreLeaveAmendmentRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $amendment = $this->amendmentService->submitAgentAmendment(
            requester: $request->user(),
            leaveRequest: $leaveRequest,
            data: $request->validated(),
        );

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'leave-amendment-submitted');
    }

    public function approve(ReviewLeaveAmendmentRequest $request, LeaveRequestAmendment $amendment): RedirectResponse
    {
        $this->amendmentService->approve(
            amendment: $amendment,
            reviewer: $request->user(),
            reviewNotes: $request->validated('review_notes'),
        );

        return $this->redirectAfterReview($request, $amendment, 'leave-amendment-approved');
    }

    public function reject(ReviewLeaveAmendmentRequest $request, LeaveRequestAmendment $amendment): RedirectResponse
    {
        $this->amendmentService->reject(
            amendment: $amendment,
            reviewer: $request->user(),
            reviewNotes: $request->validated('review_notes'),
        );

        return $this->redirectAfterReview($request, $amendment, 'leave-amendment-rejected');
    }

    private function redirectAfterReview(
        ReviewLeaveAmendmentRequest $request,
        LeaveRequestAmendment $amendment,
        string $status,
    ): RedirectResponse {
        $returnTo = (string) $request->input('return_to', '');

        if ($returnTo === 'index') {
            return redirect()
                ->route('leave-requests.index')
                ->with('status', $status);
        }

        return redirect()
            ->route('leave-requests.show', $amendment->leaveRequest)
            ->with('status', $status);
    }
}
