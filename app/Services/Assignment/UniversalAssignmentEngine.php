<?php

namespace App\Services\Assignment;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentQueue;
use App\Enums\Assignment\AssignmentTrigger;
use App\Enums\Assignment\EmailAssignmentClassification;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\AssignmentQueueResolver;
use App\Support\Assignment\CommunicationOwnershipGuard;
use App\Support\Assignment\Contracts\AssignmentStrategy;
use App\Support\Assignment\Strategies\AppointmentAssignmentStrategy;
use App\Support\Assignment\Strategies\CompletedAssignmentStrategy;
use App\Support\Assignment\Strategies\EmailTriageAssignmentStrategy;
use App\Support\Assignment\Strategies\ReadyQueueAssignmentStrategy;
use App\Support\Assignment\Strategies\SupportQueueAssignmentStrategy;
use App\Support\Assignment\Strategies\WaitingCustomerAssignmentStrategy;
use Illuminate\Support\Carbon;

class UniversalAssignmentEngine
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AssignmentQueueResolver $queueResolver,
        private readonly CommunicationOwnershipGuard $ownershipGuard,
        private readonly AppointmentAssignmentStrategy $appointmentStrategy,
        private readonly EmailTriageAssignmentStrategy $emailTriageStrategy,
        private readonly ReadyQueueAssignmentStrategy $readyQueueStrategy,
        private readonly SupportQueueAssignmentStrategy $supportQueueStrategy,
        private readonly WaitingCustomerAssignmentStrategy $waitingCustomerStrategy,
        private readonly CompletedAssignmentStrategy $completedStrategy,
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
     *
     * Production note: communication intake intentionally routes through the
     * Support queue strategy even when the incident classifies as Ready Queue.
     * This preserves legacy round-robin behaviour for unassigned ready cases.
     */
    public function assignForUnassignedIntake(
        Incident $incident,
        User $actor,
        ?Carbon $at = null,
        ?string $fallbackAuditEvent = null,
    ): Incident {
        return $this->dispatch(
            AssignmentRequest::make(
                incident: $incident->fresh(['assignee', 'order']),
                actor: $actor,
                trigger: AssignmentTrigger::CommunicationIntake,
                at: $at,
                fallbackAuditEvent: $fallbackAuditEvent,
            ),
            forceQueue: AssignmentQueue::Support,
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

        if ($this->ownershipGuard->preservesOwnership($incident)) {
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
        return $this->dispatch(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::GraceExpired,
            ),
            forceQueue: $validationPassed ? AssignmentQueue::Ready : AssignmentQueue::Support,
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
        return $this->dispatch(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::ValidationSuccess,
                at: $at,
            ),
            forceQueue: AssignmentQueue::Ready,
        );
    }

    public function reassignForValidationSuccess(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->assignmentService->reassignToShiftAdminAfterValidation($incident, $actor, $at);
    }

    public function assignForValidationFailure(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->dispatch(
            AssignmentRequest::make(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::ValidationFailure,
                at: $at,
            ),
            forceQueue: AssignmentQueue::Support,
        );
    }

    private function dispatch(AssignmentRequest $request, ?AssignmentQueue $forceQueue = null): Incident
    {
        $incident = $request->incident->fresh(['assignee', 'order', 'supportAppointments', 'activeWaitingState']);

        if ($this->ownershipGuard->shouldSkipAssignment($incident, $request->trigger)) {
            return $incident;
        }

        $queue = $forceQueue ?? $this->queueResolver->resolve($incident);

        return $this->strategyFor($queue)->assign(
            new AssignmentRequest(
                incident: $incident,
                actor: $request->actor,
                trigger: $request->trigger,
                at: $request->at,
                emailClassification: $request->emailClassification,
                preserveExistingOwnership: $request->preserveExistingOwnership,
                fallbackAuditEvent: $request->fallbackAuditEvent,
            ),
        );
    }

    private function strategyFor(AssignmentQueue $queue): AssignmentStrategy
    {
        return match ($queue) {
            AssignmentQueue::Ready => $this->readyQueueStrategy,
            AssignmentQueue::Support => $this->supportQueueStrategy,
            AssignmentQueue::WaitingCustomer => $this->waitingCustomerStrategy,
            AssignmentQueue::Completed => $this->completedStrategy,
        };
    }
}
