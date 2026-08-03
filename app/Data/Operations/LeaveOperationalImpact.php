<?php

namespace App\Data\Operations;

/**
 * Read-only operational impact snapshot for leave review (Phase 2A).
 * Never mutates ownership, assignment, attendance, or payroll.
 */
readonly class LeaveOperationalImpact
{
    /**
     * @param  list<array{
     *     key: string,
     *     label: string,
     *     count: int|string,
     *     severity: string,
     *     severity_label: string,
     *     view_url: ?string,
     *     display: string
     * }>  $sections
     * @param  array{
     *     open_cases: ?string,
     *     appointments: ?string,
     *     ready_queue: ?string,
     *     workforce: ?string,
     *     refunds: ?string
     * }  $shortcuts
     */
    public function __construct(
        public int $userId,
        public string $employeeName,
        public bool $hasWorkload,
        public string $warningMessage,
        public array $sections,
        public array $shortcuts,
        public string $attendanceLabel,
        public string $availabilityLabel,
        public bool $hasOpenShift,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'employee_name' => $this->employeeName,
            'has_workload' => $this->hasWorkload,
            'warning_message' => $this->warningMessage,
            'sections' => $this->sections,
            'shortcuts' => $this->shortcuts,
            'attendance_label' => $this->attendanceLabel,
            'availability_label' => $this->availabilityLabel,
            'has_open_shift' => $this->hasOpenShift,
        ];
    }
}
