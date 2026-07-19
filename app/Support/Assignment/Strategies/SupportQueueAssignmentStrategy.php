<?php

namespace App\Support\Assignment\Strategies;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentCapability;
use App\Enums\Assignment\AssignmentQueue;
use App\Enums\Assignment\AssignmentTrigger;
use App\Models\Incident;
use App\Services\AuditLogService;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\AssignmentCapabilityResolver;
use App\Support\Assignment\Contracts\AssignmentStrategy;
use Illuminate\Support\Carbon;

class SupportQueueAssignmentStrategy implements AssignmentStrategy
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AssignmentCapabilityResolver $capabilityResolver,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function queue(): AssignmentQueue
    {
        return AssignmentQueue::Support;
    }

    public function assign(AssignmentRequest $request): Incident
    {
        $incident = $request->incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        if ($request->trigger === AssignmentTrigger::ValidationFailure) {
            return $this->assignmentService->reassignToSupportAgentViaRoundRobin(
                incident: $incident,
                actor: $request->actor,
                at: $request->at,
            );
        }

        if ($request->trigger === AssignmentTrigger::OnCreate
            || $request->trigger === AssignmentTrigger::GraceExpired) {
            return $this->assignmentService->assignViaRoundRobinAfterGracePeriod(
                incident: $incident,
                actor: $request->actor,
            );
        }

        return $this->assignUnassignedIntake($request, $incident);
    }

    private function assignUnassignedIntake(AssignmentRequest $request, Incident $incident): Incident
    {
        $at = $request->at ?? now();
        $actor = $request->actor;

        if ($incident->order?->isInquiryOrder()) {
            if ($this->capabilityResolver->isWithinSupportHours($at)) {
                return $this->assignmentService->assignViaRoundRobinAfterGracePeriod($incident, $actor);
            }

            return $this->assignmentService->assignInquiryViaRoundRobin($incident, $actor, $at);
        }

        if ($this->capabilityResolver->isWithinSupportHours($at)) {
            $incident = $this->assignmentService->assignViaRoundRobinAfterGracePeriod($incident, $actor);

            if ($incident->assigned_to_user_id !== null) {
                return $incident;
            }

            return $this->assignCapabilityFallback(
                incident: $incident,
                actor: $actor,
                at: $at,
                capability: AssignmentCapability::ReadyQueueAdmin,
                auditEvent: $this->auditEventForTrigger($request),
                auditContext: [
                    'assignment_method' => $request->trigger->value,
                    'assignment_override' => true,
                    'override_reason' => 'shift_admin_fallback',
                    'reason' => 'no_active_support_agents',
                ],
            );
        }

        return $this->assignCapabilityFallback(
            incident: $incident,
            actor: $actor,
            at: $at,
            capability: AssignmentCapability::AfterHoursSupport,
            auditEvent: $this->auditEventForTrigger($request),
            auditContext: [
                'assignment_method' => $request->trigger->value,
                'assignment_override' => true,
                'override_reason' => 'after_hours_shift_admin',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function assignCapabilityFallback(
        Incident $incident,
        \App\Models\User $actor,
        Carbon $at,
        AssignmentCapability $capability,
        string $auditEvent,
        array $auditContext,
    ): Incident {
        $assignee = $this->capabilityResolver->resolve($capability, $at);

        if ($assignee === null) {
            return $incident;
        }

        $assigned = $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: $auditContext,
        );

        if (isset($auditContext['reason'])) {
            $this->auditLogService->log(
                userId: $actor->id,
                event: $auditEvent,
                auditable: $assigned,
                newValues: [
                    'reason' => $auditContext['reason'],
                    'assigned_to_user_id' => $assigned->assigned_to_user_id,
                ],
            );
        }

        return $assigned;
    }

    private function auditEventForTrigger(AssignmentRequest $request): string
    {
        if ($request->fallbackAuditEvent !== null) {
            return $request->fallbackAuditEvent;
        }

        return match ($request->trigger) {
            AssignmentTrigger::CommunicationIntake => 'incoming_email.assignment_fallback',
            default => 'assignment.capability_fallback',
        };
    }
}
