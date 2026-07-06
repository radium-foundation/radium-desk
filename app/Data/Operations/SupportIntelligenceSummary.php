<?php

namespace App\Data\Operations;

readonly class SupportIntelligenceSummary
{
    /**
     * @param  list<array{name: string, today: int, pending: int, active_cases: int}>  $teamWorkload
     */
    public function __construct(
        public int $scheduledToday,
        public int $completedToday,
        public int $pendingToday,
        public int $missedOverdue,
        public int $tomorrow,
        public int $nextSevenDays,
        public int $serialRequested,
        public int $serialReceived,
        public int $serialStillWaiting,
        public array $teamWorkload,
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
            ],
            'upcoming' => [
                'tomorrow' => $this->tomorrow,
                'next_seven_days' => $this->nextSevenDays,
            ],
            'customer_response' => [
                'serial_requested' => $this->serialRequested,
                'serial_received' => $this->serialReceived,
                'still_waiting' => $this->serialStillWaiting,
            ],
            'team_workload' => $this->teamWorkload,
        ];
    }
}
