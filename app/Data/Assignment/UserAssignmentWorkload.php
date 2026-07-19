<?php

namespace App\Data\Assignment;

final class UserAssignmentWorkload
{
    public function __construct(
        public readonly int $activeAssignedCases,
        public readonly int $waitingCases,
        public readonly int $appointmentCases,
        public readonly int $totalWorkload,
    ) {}

    /**
     * @return array{
     *     active_assigned_cases: int,
     *     waiting_cases: int,
     *     appointment_cases: int,
     *     total_workload: int
     * }
     */
    public function toArray(): array
    {
        return [
            'active_assigned_cases' => $this->activeAssignedCases,
            'waiting_cases' => $this->waitingCases,
            'appointment_cases' => $this->appointmentCases,
            'total_workload' => $this->totalWorkload,
        ];
    }
}
