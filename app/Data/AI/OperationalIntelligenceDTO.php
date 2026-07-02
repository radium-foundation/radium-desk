<?php

namespace App\Data\AI;

readonly class OperationalIntelligenceDTO
{
    /**
     * @param  array<string, mixed>|null  $waitingState
     * @param  list<array{policy_key: string, action_type: string, status: string, occurred_at: \Illuminate\Support\Carbon|null}>  $automationHistory
     */
    public function __construct(
        public ?array $waitingState,
        public string $slaState,
        public string $priority,
        public ?string $assignment,
        public ?int $queuePosition,
        public array $automationHistory,
        public string $automationStatus,
        public string $timelineSummary,
        public string $internalRemarksSummary,
    ) {}
}
