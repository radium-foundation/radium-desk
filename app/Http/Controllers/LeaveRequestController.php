<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManageLeaveRequestRequest;
use App\Http\Requests\ReviewLeaveRequestRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Requests\UpdateLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Services\Operations\LeaveOperationalImpactService;
use App\Services\Operations\LeaveRequestAmendmentService;
use App\Services\Operations\LeaveRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
        private readonly LeaveRequestAmendmentService $amendmentService,
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
        $canManageLeave = $this->amendmentService->canManage($user);

        $pendingGroups = [
            'today' => collect(),
            'upcoming' => collect(),
        ];

        if ($canReviewLeave) {
            $pendingGroups = $this->leaveRequestService->pendingApprovalsGrouped();
        }

        $pendingAmendments = $canManageLeave
            ? $this->amendmentService->pendingAmendments()
            : collect();

        $canViewAll = $user->can('leave-requests.review') || $canManageLeave;

        $leaveRequests = LeaveRequest::query()
            ->with(['user', 'reviewer', 'pendingAmendment'])
            ->when(! $canViewAll, function ($query) use ($user) {
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
            'canManageLeave' => $canManageLeave,
            'pendingToday' => $pendingGroups['today'],
            'pendingUpcoming' => $pendingGroups['upcoming'],
            'pendingAmendments' => $pendingAmendments,
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
        $leaveRequest->load(['user', 'reviewer', 'pendingAmendment.requester', 'amendments.reviewer']);

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
            'canManageLeave' => $viewer?->can('manage', $leaveRequest) ?? false,
        ]);
    }

    public function edit(LeaveRequest $leaveRequest): View
    {
        $this->authorize('update', $leaveRequest);

        return view('leave-requests.edit', [
            'leaveRequest' => $leaveRequest,
        ]);
    }

    public function update(UpdateLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leaveRequestService->updatePending(
            requester: $request->user(),
            leaveRequest: $leaveRequest,
            data: $request->validated(),
        );

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'leave-request-updated');
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

    public function manageUpdate(ManageLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->amendmentService->manageDateChange(
            manager: $request->user(),
            leaveRequest: $leaveRequest,
            data: $request->validated(),
        );

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'leave-request-managed');
    }

    public function manageCancel(ManageLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->amendmentService->manageCancellation(
            manager: $request->user(),
            leaveRequest: $leaveRequest,
            data: $request->validated(),
        );

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'leave-request-cancelled');
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
