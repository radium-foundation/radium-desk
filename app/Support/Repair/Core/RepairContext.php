<?php

namespace App\Support\Repair\Core;

use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Enums\RepairPhase;
use App\Support\Repair\Models\SystemRepairBatch;

class RepairContext
{
    public function __construct(
        public readonly RepairBatchOptions $options,
        public readonly ?SystemRepairBatch $batch = null,
        public readonly bool $silent = true,
    ) {}

    public function phase(): RepairPhase
    {
        return $this->options->phase;
    }

    public function isSilent(): bool
    {
        return $this->silent;
    }

    public function isDryRun(): bool
    {
        return $this->options->dryRun || ! $this->options->execute;
    }

    public function isExecute(): bool
    {
        return $this->options->execute && ! $this->options->dryRun;
    }

    public function batchUuid(): ?string
    {
        return $this->batch?->uuid ?? $this->options->batchUuid;
    }

    public function withBatch(SystemRepairBatch $batch): self
    {
        return new self(
            options: $this->options,
            batch: $batch,
            silent: $this->silent,
        );
    }
}
