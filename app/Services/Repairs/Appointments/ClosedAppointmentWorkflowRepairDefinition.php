<?php

namespace App\Services\Repairs\Appointments;

use App\Models\Incident;
use App\Support\Repair\Contracts\CandidateResolverInterface;
use App\Support\Repair\Contracts\RepairDefinition;
use App\Support\Repair\Contracts\RepairItemHandlerInterface;
use App\Support\Repair\Contracts\RepairVerifierInterface;
use App\Support\Repair\Enums\RepairCapability;

class ClosedAppointmentWorkflowRepairDefinition implements RepairDefinition
{
    public function __construct(
        private readonly ClosedAppointmentWorkflowCandidateResolver $resolver,
        private readonly ClosedAppointmentWorkflowItemHandler $handler,
        private readonly ClosedAppointmentWorkflowVerifier $verifier,
    ) {}

    public function key(): string
    {
        return 'appointments.closed_workflow';
    }

    public function title(): string
    {
        return 'Repair closed cases with scheduled Tech Support appointments';
    }

    public function subjectType(): string
    {
        return Incident::class;
    }

    public function maxBatchSize(): int
    {
        return 500;
    }

    public function defaultLimit(): ?int
    {
        return 100;
    }

    public function capabilities(): array
    {
        return [
            RepairCapability::Rollback,
            RepairCapability::Verify,
            RepairCapability::Export,
            RepairCapability::Resume,
        ];
    }

    public function requiresDoubleConfirm(): bool
    {
        return true;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function candidateResolver(): CandidateResolverInterface
    {
        return $this->resolver;
    }

    public function itemHandler(): RepairItemHandlerInterface
    {
        return $this->handler;
    }

    public function verifier(): ?RepairVerifierInterface
    {
        return $this->verifier;
    }
}
