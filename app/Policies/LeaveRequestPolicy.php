<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;

class LeaveRequestPolicy
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('leave-requests.view');
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->can('leave-requests.view')) {
            return false;
        }

        if ($user->can('leave-requests.review')) {
            return true;
        }

        return $leaveRequest->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('leave-requests.create');
    }

    public function review(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->leaveRequestService->canReview($user, $leaveRequest);
    }
}
