<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewLeaveRequestRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Services\Operations\LeaveOperationalImpactService;
use App\Services\Operations\LeaveRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
        private readonly LeaveOperationalImpactService $leaveOperationalImpactService,
    ) {
        $this->authorizeResource(LeaveRequest::class, 'leaveRequest', [
            'except' => ['edit', 'update', 'destroy'],
        ]);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $canReviewLeave = $user->can('leave-requests.review')
            && $this->leaveRequestService->isDesignatedApprover($user);

        $pendingGroups = [
            'today' => collect(),
            'upcoming' => collect(),
        ];

        if ($canReviewLeave) {
            $pendingGroups = $this->leaveRequestService->pendingApprovalsGrouped();
        }

        $leaveRequests = LeaveRequest::query()
            ->with(['user', 'reviewer'])
            ->when(! $user->can('leave-requests.review'), function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->trim());
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('leave-requests.index', [
            'leaveRequests' => $leaveRequests,
            'filters' => $request->only(['status']),
            'canReviewLeave' => $canReviewLeave,
            'pendingToday' => $pendingGroups['today'],
            'pendingUpcoming' => $pendingGroups['upcoming'],
            'leaveRequestService' => $this->leaveRequestService,
        ]);
    }

    public function create(): View
    {
        return view('leave-requests.create');
    }

    public function store(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $leaveRequest = $this->leaveRequestService->submit(
            requester: $request->user(),
            data: $request->validated(),
        );

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'leave-request-submitted');
    }

    public function show(LeaveRequest $leaveRequest): View
    {
        $leaveRequest->load(['user', 'reviewer']);

        $viewer = request()->user();
        $showImpact = $viewer !== null
            && $this->leaveRequestService->isDesignatedApprover($viewer)
            && $viewer->can('review', $leaveRequest);

        $operationalImpact = $showImpact
            ? $this->leaveOperationalImpactService->forLeaveRequest($leaveRequest)
            : null;

        return view('leave-requests.show', [
            'leaveRequest' => $leaveRequest,
            'operationalImpact' => $operationalImpact,
        ]);
    }

    public function approve(ReviewLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leaveRequestService->approve(
            leaveRequest: $leaveRequest,
            reviewer: $request->user(),
            reviewNotes: $request->validated('review_notes'),
        );

        return $this->redirectAfterReview($request, $leaveRequest, 'leave-request-approved');
    }

    public function reject(ReviewLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leaveRequestService->reject(
            leaveRequest: $leaveRequest,
            reviewer: $request->user(),
            reviewNotes: $request->validated('review_notes'),
        );

        return $this->redirectAfterReview($request, $leaveRequest, 'leave-request-rejected');
    }

    private function redirectAfterReview(
        ReviewLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        string $status,
    ): RedirectResponse {
        $returnTo = (string) $request->input('return_to', '');

        if ($returnTo === 'index') {
            return redirect()
                ->route('leave-requests.index')
                ->with('status', $status);
        }

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', $status);
    }
}
