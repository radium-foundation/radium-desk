<?php

namespace App\Support\Assignment\Strategies;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentQueue;
use App\Models\Incident;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\Contracts\AssignmentStrategy;

class ReadyQueueAssignmentStrategy implements AssignmentStrategy
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
    ) {}

    public function queue(): AssignmentQueue
    {
        return AssignmentQueue::Ready;
    }

    public function assign(AssignmentRequest $request): Incident
    {
        $incident = $request->incident->fresh(['assignee', 'order', 'supportAppointments']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        return $this->assignmentService->assignToShiftAdminAfterValidation(
            incident: $incident,
            actor: $request->actor,
            at: $request->at,
        );
    }
}
