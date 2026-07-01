<?php

namespace App\Data\Automation;

readonly class AutomationSchedulerRunResult
{
    public function __construct(
        public bool $enabled,
        public int $waitingStatesScanned = 0,
        public int $dueActionsFound = 0,
        public int $executed = 0,
        public int $skipped = 0,
        public int $failures = 0,
    ) {}

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    /**
     * @return array<string, int|bool>
     */
    public function toLogContext(): array
    {
        return [
            'enabled' => $this->enabled,
            'waiting_states_scanned' => $this->waitingStatesScanned,
            'due_actions_found' => $this->dueActionsFound,
            'executed' => $this->executed,
            'skipped' => $this->skipped,
            'failures' => $this->failures,
        ];
    }
}
