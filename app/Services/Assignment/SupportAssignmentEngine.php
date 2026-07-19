<?php

namespace App\Services\Assignment;

use App\Data\Assignment\SupportAssignmentRequest;
use App\Data\Assignment\SupportAssignmentResult;
use App\Enums\Assignment\SupportAssignmentStrategyType;
use App\Models\Incident;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\Availability\SupportAssignmentAvailabilityResolver;
use App\Support\Assignment\Contracts\SupportAssignmentStrategy;
use App\Support\Assignment\Eligibility\SupportAssignmentEligibilityGate;
use Illuminate\Contracts\Container\Container;

class SupportAssignmentEngine
{
    public function __construct(
        private readonly SupportAssignmentEligibilityGate $eligibilityGate,
        private readonly SupportAssignmentAvailabilityResolver $availabilityResolver,
        private readonly SupportAssignmentWorkloadService $workloadService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly Container $container,
    ) {}

    public function assign(SupportAssignmentRequest $request): SupportAssignmentResult
    {
        $incident = $request->incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return SupportAssignmentResult::unchanged($incident);
        }

        $eligible = $this->eligibilityGate->eligibleAgents($request);
        $available = $this->availabilityResolver->filterAssignable($eligible);

        if ($available->isEmpty()) {
            return SupportAssignmentResult::unassigned(
                incident: $incident,
                reason: 'no_eligible_support_agents',
                context: [
                    'eligible_count' => $eligible->count(),
                    'available_count' => 0,
                    'strategy' => $this->activeStrategyType()->value,
                ],
            );
        }

        $strategy = $this->resolveStrategy();
        $assignee = $strategy->selectAssignee($available, $request);

        if ($assignee === null) {
            return SupportAssignmentResult::unassigned(
                incident: $incident,
                reason: 'strategy_returned_no_assignee',
                context: [
                    'strategy' => $strategy->type(),
                    'eligible_count' => $eligible->count(),
                    'available_count' => $available->count(),
                ],
            );
        }

        $assignedIncident = $this->applyAssignment($request, $incident, $assignee);

        return SupportAssignmentResult::assigned(
            incident: $assignedIncident,
            assignee: $assignee,
            reasons: [$strategy->type()],
            context: [
                'strategy' => $strategy->type(),
                'eligible_count' => $eligible->count(),
                'available_count' => $available->count(),
                'workload' => $this->workloadService->forUser($assignee)->toArray(),
            ],
        );
    }

    public function activeStrategyType(): SupportAssignmentStrategyType
    {
        return SupportAssignmentStrategyType::fromConfig();
    }

    private function resolveStrategy(): SupportAssignmentStrategy
    {
        $type = $this->activeStrategyType();
        $class = config("support_assignment.strategies.{$type->value}");

        if (! is_string($class) || ! class_exists($class)) {
            $class = config('support_assignment.strategies.round_robin');
        }

        return $this->container->make($class);
    }

    private function applyAssignment(
        SupportAssignmentRequest $request,
        Incident $incident,
        \App\Models\User $assignee,
    ): Incident {
        if ($request->auditContext !== []) {
            return $this->assignmentService->assignWithAuditContext(
                incident: $incident,
                assignee: $assignee,
                actor: $request->actor,
                auditContext: $request->auditContext,
            );
        }

        return $this->assignmentService->applySupportAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $request->actor,
            event: $request->auditEvent,
            extraNewValues: $request->unassignedReason !== null
                ? ['reason' => $request->unassignedReason]
                : [],
        );
    }
}
