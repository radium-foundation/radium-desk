<?php

namespace App\Services\Workforce\Payroll;

/**
 * Phase 1 base wage: day rate × payable days.
 * Extension point for future earning/deduction providers.
 */
class PayrollBaseSalaryCalculator
{
    /**
     * @return array{calendar_days: int, day_rate: float, net_salary: float}
     */
    public function calculate(float $monthlySalary, int $calendarDays, float $payableDays): array
    {
        $dayRate = $calendarDays > 0 ? round($monthlySalary / $calendarDays, 4) : 0.0;
        $netSalary = round($dayRate * $payableDays, 2);

        return [
            'calendar_days' => $calendarDays,
            'day_rate' => $dayRate,
            'net_salary' => $netSalary,
        ];
    }
}
