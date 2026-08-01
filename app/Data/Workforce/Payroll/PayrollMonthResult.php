<?php

namespace App\Data\Workforce\Payroll;

use App\Models\EmployeeSalary;
use App\Models\PayrollRunLine;
use Illuminate\Support\Carbon;

readonly class PayrollMonthResult
{
    public function __construct(
        public int $userId,
        public string $employeeName,
        public Carbon $month,
        public float $monthlySalary,
        public int $calendarDays,
        public float $dayRate,
        public float $payableDays,
        public float $nonPayableDays,
        public float $grossSalary,
        public float $netSalary,
        public int $presentDays,
        public int $lateDays,
        public int $leaveDays,
        public int $halfDayDays,
        public int $weeklyOffDays,
        public int $holidayDays,
        public int $absentDays,
        public int $extraDays,
        public ?EmployeeSalary $salaryRecord,
        public bool $isSnapshot = false,
    ) {}

    public static function fromRunLine(PayrollRunLine $line, Carbon $month): self
    {
        $summary = $line->attendance_summary_json ?? [];

        return new self(
            userId: (int) $line->user_id,
            employeeName: (string) ($line->user?->name ?? 'Employee #'.$line->user_id),
            month: $month->copy()->startOfMonth(),
            monthlySalary: (float) $line->monthly_salary_snapshot,
            calendarDays: (int) $line->calendar_days,
            dayRate: (float) $line->day_rate,
            payableDays: (float) $line->payable_days,
            nonPayableDays: (float) $line->non_payable_days,
            grossSalary: (float) $line->gross_salary,
            netSalary: (float) $line->net_salary,
            presentDays: (int) ($summary['present'] ?? 0),
            lateDays: (int) ($summary['late'] ?? 0),
            leaveDays: (int) ($summary['leave'] ?? 0),
            halfDayDays: (int) ($summary['half_day'] ?? 0),
            weeklyOffDays: (int) ($summary['weekly_off'] ?? 0),
            holidayDays: (int) ($summary['holiday'] ?? 0),
            absentDays: (int) ($summary['absent'] ?? 0),
            extraDays: (int) ($summary['extra'] ?? 0),
            salaryRecord: $line->salaryRevision,
            isSnapshot: true,
        );
    }
}
