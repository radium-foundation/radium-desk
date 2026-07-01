<?php

namespace App\Data\Automation;

readonly class AutomationRuntimeResult
{
    /**
     * @param  list<AutomationExecutionResult>  $results
     */
    public function __construct(
        public array $results,
    ) {}

    /**
     * @param  list<AutomationExecutionResult>  $results
     */
    public static function fromResults(array $results): self
    {
        return new self(results: $results);
    }

    public function executedCount(): int
    {
        return count(array_filter(
            $this->results,
            fn (AutomationExecutionResult $result): bool => ! $result->wasSkipped(),
        ));
    }

    public function skippedCount(): int
    {
        return count(array_filter(
            $this->results,
            fn (AutomationExecutionResult $result): bool => $result->wasSkipped(),
        ));
    }
}
