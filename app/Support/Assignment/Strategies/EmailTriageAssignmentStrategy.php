<?php

namespace App\Support\Assignment\Strategies;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentCapability;
use App\Enums\Assignment\AssignmentQueue;
use App\Enums\Assignment\AssignmentTrigger;
use App\Enums\Assignment\EmailAssignmentClassification;
use App\Models\Incident;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\AssignmentCapabilityResolver;
use App\Support\Assignment\Contracts\AssignmentStrategy;

class EmailTriageAssignmentStrategy implements AssignmentStrategy
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AssignmentCapabilityResolver $capabilityResolver,
        private readonly SupportQueueAssignmentStrategy $supportQueueStrategy,
    ) {}

    public function queue(): AssignmentQueue
    {
        return AssignmentQueue::Support;
    }

    public function assign(AssignmentRequest $request): Incident
    {
        $classification = $request->emailClassification;

        if ($classification === EmailAssignmentClassification::ExistingCaseAttachOnly) {
            return $request->incident->fresh(['assignee', 'order']);
        }

        if ($classification === EmailAssignmentClassification::SalesLead) {
            return $this->assignViaCapability($request, AssignmentCapability::SalesLeadHandler);
        }

        if ($classification === EmailAssignmentClassification::UnknownEmail) {
            return $this->assignViaCapability($request, AssignmentCapability::IncomingEmailSupervisor);
        }

        if ($classification === EmailAssignmentClassification::NewSupportCase) {
            return $this->supportQueueStrategy->assign(
                AssignmentRequest::make(
                    incident: $request->incident,
                    actor: $request->actor,
                    trigger: AssignmentTrigger::CommunicationIntake,
                    at: $request->at,
                ),
            );
        }

        return $this->supportQueueStrategy->assign($request);
    }

    private function assignViaCapability(AssignmentRequest $request, AssignmentCapability $capability): Incident
    {
        $incident = $request->incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $assignee = $this->capabilityResolver->resolve($capability, $request->at);

        if ($assignee === null) {
            return $incident;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $request->actor,
            auditContext: [
                'assignment_method' => 'email_triage',
                'assignment_capability' => $capability->value,
                'email_classification' => $request->emailClassification?->value,
            ],
        );
    }
}
