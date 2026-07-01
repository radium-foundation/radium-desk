<?php

namespace App\Data\Automation;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;

readonly class AutomationExecutionResult
{
    public function __construct(
        public AutomationExecution $execution,
        public AutomationExecutionStatus $status,
        public bool $skippedExisting = false,
    ) {}

    public function wasSkipped(): bool
    {
        return $this->skippedExisting
            || $this->status === AutomationExecutionStatus::Skipped;
    }
}
