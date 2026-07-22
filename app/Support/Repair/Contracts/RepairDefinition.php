<?php

namespace App\Support\Repair\Contracts;

use App\Support\Repair\Enums\RepairCapability;

interface RepairDefinition
{
    public function key(): string;

    public function title(): string;

    /**
     * @return class-string
     */
    public function subjectType(): string;

    public function maxBatchSize(): int;

    public function defaultLimit(): ?int;

    /**
     * @return list<RepairCapability>
     */
    public function capabilities(): array;

    public function requiresDoubleConfirm(): bool;

    public function supportsRollback(): bool;

    public function candidateResolver(): CandidateResolverInterface;

    public function itemHandler(): RepairItemHandlerInterface;

    public function verifier(): ?RepairVerifierInterface;
}
