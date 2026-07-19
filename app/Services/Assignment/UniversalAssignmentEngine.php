<?php

namespace App\Services\Assignment;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentTrigger;
use App\Enums\Assignment\EmailAssignmentClassification;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\Strategies\AppointmentAssignmentStrategy;
use App\Support\Assignment\Strategies\EmailTriageAssignmentStrategy;
use App\Support\Assignment\Strategies\ReadyQueueAssignmentStrategy;
use App\Support\Assignment\Strategies\SupportQueueAssignmentStrategy;
use Illuminate\Support\Carbon;

class UniversalAssignmentEngine
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AppointmentAssignmentStrategy $appointmentStrategy,
        private readonly EmailTriageAssignmentStrategy $emailTriageStrategy,
        private readonly ReadyQueueAssignmentStrategy $readyQueueStrategy,
        private readonly SupportQueueAssignmentStrategy $supportQueueStrategy,
    ) {}

    public function assignOnCreate(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->assignmentService->assignOnCreate($incident, $actor, $at);
    }

    /**
     * Communication events must never change existing ownership.
     */
    public function assignForCommunicationIntake(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->assignForUnassignedIntake($incident, $actor, $at);
    }

    /**
     * Queue-based assignment for unassigned intake. Channel-agnostic.
     */
    public function assignForUnassignedIntake(
        Incident $incident,
        User $actor,
        ?Carbon $at = null,
        ?string $fallbackAuditEvent = null,
    ): Incident {
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        return $this->supportQueueStrategy->assign(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::CommunicationIntake,
                at: $at,
                fallbackAuditEvent: $fallbackAuditEvent,
            ),
        );
    }

    /**
     * Future email classification entry point. Phase 1 routes through extensible strategies.
     */
    public function assignForEmailClassification(
        Incident $incident,
        User $actor,
        EmailAssignmentClassification $classification,
        ?Carbon $at = null,
    ): Incident {
        $incident = $incident->fresh(['assignee', 'order']);

        if ($classification === EmailAssignmentClassification::ExistingCaseAttachOnly) {
            return $incident;
        }

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        return $this->emailTriageStrategy->assign(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::EmailTriage,
                at: $at,
                emailClassification: $classification,
            ),
        );
    }

    public function assignAfterGraceExpiry(Incident $incident, User $actor, bool $validationPassed): Incident
    {
        if ($validationPassed) {
            return $this->readyQueueStrategy->assign(
                AssignmentRequest::make(
                    incident: $incident,
                    actor: $actor,
                    trigger: AssignmentTrigger::GraceExpired,
                ),
            );
        }

        return $this->supportQueueStrategy->assign(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::GraceExpired,
            ),
        );
    }

    public function assignForActiveAppointment(Incident $incident, User $actor): Incident
    {
        return $this->appointmentStrategy->assign(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::AppointmentBooked,
            ),
        );
    }

    public function assignAfterBooking(
        Incident $incident,
        SupportAppointment $appointment,
        ?User $actor = null,
    ): Incident {
        return $this->appointmentStrategy->assignAfterBooking($incident, $appointment, $actor);
    }

    public function assignForValidationSuccess(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->readyQueueStrategy->assign(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::ValidationSuccess,
                at: $at,
            ),
        );
    }

    public function reassignForValidationSuccess(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->assignmentService->reassignToShiftAdminAfterValidation($incident, $actor, $at);
    }

    public function assignForValidationFailure(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->supportQueueStrategy->assign(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::ValidationFailure,
                at: $at,
            ),
        );
    }
}
