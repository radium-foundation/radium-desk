<?php

namespace App\Data\Operations;

readonly class SupportIntelligenceSummary
{
    /**
     * @param  list<array{name: string, action_needed: int, today: int, scheduled_today: int, scheduled_future: int, active_cases: int}>  $teamWorkload
     * @param  array<string, int>  $operationalMetrics
     */
    public function __construct(
        public int $scheduledToday,
        public int $completedToday,
        public int $pendingToday,
        public int $missedOverdue,
        public int $unassignedScheduled,
        public int $tomorrow,
        public int $nextSevenDays,
        public int $serialRequested,
        public int $serialReceived,
        public int $serialStillWaiting,
        public array $teamWorkload,
        public array $operationalMetrics = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'today' => [
                'scheduled' => $this->scheduledToday,
                'completed' => $this->completedToday,
                'pending' => $this->pendingToday,
                'missed_overdue' => $this->missedOverdue,
                'unassigned_scheduled' => $this->unassignedScheduled,
            ],
            'upcoming' => [
                'tomorrow' => $this->tomorrow,
                'next_seven_days' => $this->nextSevenDays,
            ],
            'customer_response' => [
                'serial_requested' => $this->serialRequested,
                'serial_received' => $this->serialReceived,
                'serial_response_pending' => $this->serialStillWaiting,
            ],
            'team_workload' => $this->teamWorkload,
            'operational' => $this->operationalMetrics,
        ];
    }
}
