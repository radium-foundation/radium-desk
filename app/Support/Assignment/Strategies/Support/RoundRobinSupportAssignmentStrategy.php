<?php

namespace App\Support\Assignment\Strategies\Support;

use App\Data\Assignment\SupportAssignmentRequest;
use App\Enums\Assignment\SupportAssignmentStrategyType;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\Contracts\SupportAssignmentStrategy;
use Illuminate\Support\Collection;

class RoundRobinSupportAssignmentStrategy implements SupportAssignmentStrategy
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
    ) {}

    public function type(): string
    {
        return SupportAssignmentStrategyType::RoundRobin->value;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function selectAssignee(Collection $candidates, SupportAssignmentRequest $request): ?User
    {
        return $this->assignmentService->resolveSupportAgentViaRoundRobin(
            at: $request->at(),
            order: $request->incident->order,
        );
    }
}
