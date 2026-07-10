<?php

namespace App\Services;

use App\Enums\ServiceCaseCloseExceptionReason;
use App\Models\Incident;
use App\Models\User;
use App\Services\Automation\CustomerWaitingLifecycleService;
use Database\Seeders\RolePermissionSeeder;

class CustomerUnreachableCloseEligibilityService
{
    public function __construct(
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly CustomerWaitingLifecycleService $customerWaitingLifecycleService,
    ) {}

    public function ineligibilityReason(
        Incident $incident,
        ServiceCaseCloseExceptionReason $reason,
        User $actor,
    ): ?string {
        if ($reason !== ServiceCaseCloseExceptionReason::CustomerNotResponding) {
            return null;
        }

        if ($actor->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            return null;
        }

        if ($this->hasSatisfiedFollowUpRequirement($incident)) {
            return null;
        }

        return 'Send the customer follow-up and wait for the response window before closing as customer not responding.';
    }

    private function hasSatisfiedFollowUpRequirement(Incident $incident): bool
    {
        $activeWaitingState = $this->waitingStateService->activeFor($incident);

        if ($activeWaitingState?->customer_followup_sent_at !== null) {
            return true;
        }

        $lifecycleHistory = $this->customerWaitingLifecycleService->lifecycleHistory($incident);

        return ($lifecycleHistory['customer_followup_sent_at'] ?? null) !== null;
    }
}
