<?php

namespace App\Support\Assignment\Availability;

use App\Enums\Assignment\SupportAgentAvailabilityStatus;
use App\Models\User;
use App\Services\Operations\TeamAvailabilityService;
use Illuminate\Support\Collection;

class SupportAssignmentAvailabilityResolver
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
    ) {}

    public function resolve(User $user): SupportAgentAvailabilityStatus
    {
        return SupportAgentAvailabilityStatus::fromTeamAvailability(
            $this->availabilityService->statusFor($user),
        );
    }

    public function isAssignable(User $user): bool
    {
        return $this->resolve($user)->isAssignableForSupport();
    }

    /**
     * Production parity: eligibility already enforces offline/leave blocks.
     * This gate is architecture-only until finer statuses are adopted.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function filterAssignable(Collection $candidates): Collection
    {
        return $candidates
            ->filter(fn (User $user): bool => $this->isAssignable($user))
            ->values();
    }
}
