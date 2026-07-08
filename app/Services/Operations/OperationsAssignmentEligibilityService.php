<?php

namespace App\Services\Operations;

use App\Models\User;
use Illuminate\Support\Carbon;

class OperationsAssignmentEligibilityService
{
    public function __construct(
        private readonly WorkforceAuthorityService $workforceAuthority,
    ) {}

    public function isEligible(User $user, ?Carbon $at = null): bool
    {
        return $this->workforceAuthority->isEligibleForNormalAssignment($user, $at);
    }

    public function isEligibleWithOverride(User $user, bool $allowOverride = false, ?Carbon $at = null): bool
    {
        if ($allowOverride) {
            return $user->is_active && ! $user->trashed();
        }

        return $this->isEligible($user, $at);
    }
}
