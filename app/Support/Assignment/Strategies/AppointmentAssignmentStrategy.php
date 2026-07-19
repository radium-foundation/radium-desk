<?php

namespace App\Support\Assignment\Strategies;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentQueue;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use App\Support\Assignment\Contracts\AssignmentStrategy;

class AppointmentAssignmentStrategy implements AssignmentStrategy
{
    public function __construct(
        private readonly SupportAppointmentSmartAssignmentService $smartAssignmentService,
    ) {}

    public function queue(): AssignmentQueue
    {
        return AssignmentQueue::Support;
    }

    public function assign(AssignmentRequest $request): Incident
    {
        return $this->smartAssignmentService->assignForActiveSupport(
            incident: $request->incident,
            actor: $request->actor,
        );
    }

    public function assignAfterBooking(
        Incident $incident,
        SupportAppointment $appointment,
        ?User $actor = null,
    ): Incident {
        return $this->smartAssignmentService->assignAfterBooking(
            incident: $incident,
            appointment: $appointment,
            actor: $actor,
        );
    }
}
