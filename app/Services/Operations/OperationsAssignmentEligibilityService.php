<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class OperationsAssignmentEligibilityService
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function isEligible(User $user, ?Carbon $at = null): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        if (! $this->workCalendarService->isEligibleForAssignment($user, $at)) {
            return false;
        }

        $status = $this->availabilityService->statusFor($user);

        return in_array($status, [
            TeamAvailabilityStatus::Available,
            TeamAvailabilityStatus::Busy,
        ], true);
    }
}
