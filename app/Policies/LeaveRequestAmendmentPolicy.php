<?php

namespace App\Policies;

use App\Models\LeaveRequestAmendment;
use App\Models\User;
use App\Services\Operations\LeaveRequestAmendmentService;

class LeaveRequestAmendmentPolicy
{
    public function __construct(
        private readonly LeaveRequestAmendmentService $amendmentService,
    ) {}

    public function review(User $user, LeaveRequestAmendment $amendment): bool
    {
        return $this->amendmentService->canReviewAmendment($user, $amendment);
    }
}
