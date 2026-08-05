<?php

namespace App\Data\PerformanceIntelligence;

/**
 * Raw inputs for one employee × one calendar day (Phase 0).
 * Stored on the snapshot for debugging — not a second source of truth.
 */
readonly class PerformanceDayInputs
{
    /**
     * @param  array<string, int>  $touchBreakdown
     */
    public function __construct(
        public int $userId,
        public string $workDate,
        public int $casesWorked,
        public int $customerTouches,
        public array $touchBreakdown,
        public int $resolvedCount,
        public int $closedCount,
        public int $reopenCount,
        public int $refundDecisionCount,
        public int $assignOrEscalateCount,
        public int $answeredCallCount,
        public bool $attendanceExtra,
        public bool $attendanceOnLeave,
        public bool $isCompanyHoliday,
        public bool $isWorkingDay,
        public int $overtimeSeconds,
        public ?string $attendanceStatus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'work_date' => $this->workDate,
            'cases_worked' => $this->casesWorked,
            'customer_touches' => $this->customerTouches,
            'touch_breakdown' => $this->touchBreakdown,
            'resolved_count' => $this->resolvedCount,
            'closed_count' => $this->closedCount,
            'reopen_count' => $this->reopenCount,
            'refund_decision_count' => $this->refundDecisionCount,
            'assign_or_escalate_count' => $this->assignOrEscalateCount,
            'answered_call_count' => $this->answeredCallCount,
            'attendance_extra' => $this->attendanceExtra,
            'attendance_on_leave' => $this->attendanceOnLeave,
            'is_company_holiday' => $this->isCompanyHoliday,
            'is_working_day' => $this->isWorkingDay,
            'overtime_seconds' => $this->overtimeSeconds,
            'attendance_status' => $this->attendanceStatus,
        ];
    }
}
