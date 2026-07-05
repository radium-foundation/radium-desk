<?php

namespace App\Data\Operations;

readonly class SmartAssignmentFeedbackMetrics
{
    public function __construct(
        public int $userId,
        public ?float $averageResolutionMinutes,
        public ?float $currentEfficiency,
        public ?float $workloadCapacity,
        public int $openCases,
        public int $scheduledCases,
        public int $activeCases,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'average_resolution_minutes' => $this->averageResolutionMinutes,
            'current_efficiency' => $this->currentEfficiency,
            'workload_capacity' => $this->workloadCapacity,
            'open_cases' => $this->openCases,
            'scheduled_cases' => $this->scheduledCases,
            'active_cases' => $this->activeCases,
        ];
    }
}
