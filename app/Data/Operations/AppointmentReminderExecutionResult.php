<?php

namespace App\Data\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;

readonly class AppointmentReminderExecutionResult
{
    public function __construct(
        public AutomationExecution $execution,
        public AutomationExecutionStatus $status,
        public bool $skippedExisting = false,
        public bool $telegramSent = false,
    ) {}

    public function wasSkipped(): bool
    {
        return $this->skippedExisting
            || $this->status === AutomationExecutionStatus::Skipped;
    }
}
