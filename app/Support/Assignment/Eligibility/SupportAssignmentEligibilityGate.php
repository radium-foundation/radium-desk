<?php

namespace App\Support\Assignment\Eligibility;

use App\Data\Assignment\SupportAssignmentRequest;
use App\Enums\Assignment\AssignmentCapability;
use App\Models\User;
use App\Services\Operations\OperationsAssignmentEligibilityService;
use App\Services\Operations\OperationsRoleService;
use App\Support\Assignment\Capabilities\UserCapabilityService;
use Illuminate\Support\Collection;

class SupportAssignmentEligibilityGate
{
    public function __construct(
        private readonly UserCapabilityService $capabilityService,
        private readonly OperationsAssignmentEligibilityService $assignmentEligibilityService,
        private readonly OperationsRoleService $operationsRoleService,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function eligibleAgents(SupportAssignmentRequest $request): Collection
    {
        $at = $request->at();
        $order = $request->incident->order;

        return $this->capabilityService
            ->eligibleUsers(AssignmentCapability::SupportAgent, $at)
            ->filter(function (User $user) use ($at, $order): bool {
                if (! $this->operationsRoleService->isNormalAssignmentPool($user)) {
                    return false;
                }

                return $this->assignmentEligibilityService->isEligible($user, $at);
            })
            ->values();
    }
}
