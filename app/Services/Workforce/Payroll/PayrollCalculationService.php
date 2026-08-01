<?php

namespace App\Services\Workforce\Payroll;

use App\Contracts\Workforce\Payroll\PayrollDeductionProvider;
use App\Contracts\Workforce\Payroll\PayrollEarningProvider;
use App\Data\Workforce\Payroll\PayrollMonthResult;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 1 payroll orchestration.
 *
 * Separated concerns:
 * - Salary resolution (EmployeeSalaryService)
 * - Attendance summary (MonthlyAttendanceMatrixService cells)
 * - Payable day policy (PayrollPayableDayPolicy)
 * - Base salary calculation (PayrollBaseSalaryCalculator)
 *
 * Earning/deduction providers are extension points (empty in Phase 1).
 */
class PayrollCalculationService
{
    /**
     * @param  list<PayrollEarningProvider>  $earningProviders
     * @param  list<PayrollDeductionProvider>  $deductionProviders
     */
    public function __construct(
        private readonly MonthlyAttendanceMatrixService $monthlyAttendanceMatrix,
        private readonly EmployeeSalaryService $employeeSalaryService,
        private readonly PayrollPayableDayPolicy $payableDayPolicy,
        private readonly PayrollBaseSalaryCalculator $baseSalaryCalculator,
        private readonly OperationsRoleService $roleService,
        private readonly array $earningProviders = [],
        private readonly array $deductionProviders = [],
    ) {}

    public function calculateForUser(User $user, Carbon $month): ?PayrollMonthResult
    {
        $monthStart = $month->copy()->startOfMonth();

        // 1. Salary resolution
        $salary = $this->employeeSalaryService->salaryForMonth($user, $monthStart);
        if ($salary === null) {
            return null;
        }

        // 2. Attendance summary (cells from existing matrix engine)
        $row = $this->monthlyAttendanceMatrix->buildForUser($user, $monthStart);
        $leavePayClassByDate = $this->payableDayPolicy->leavePayClassByDateForMonth($user, $monthStart);

        // 3. Payable day policy
        $breakdown = $this->payableDayPolicy->summarize($row->cells, $leavePayClassByDate);
        $payableDays = round($breakdown['payable_days'], 1);
        $nonPayableDays = round($breakdown['non_payable_days'], 1);

        // 4. Base salary calculation
        $monthlySalary = (float) $salary->monthly_salary;
        $calendarDays = $monthStart->daysInMonth();
        $base = $this->baseSalaryCalculator->calculate($monthlySalary, $calendarDays, $payableDays);

        $netSalary = $base['net_salary'];

        $result = new PayrollMonthResult(
            userId: $user->id,
            employeeName: (string) $user->name,
            month: $monthStart,
            monthlySalary: $monthlySalary,
            calendarDays: $base['calendar_days'],
            dayRate: $base['day_rate'],
            payableDays: $payableDays,
            nonPayableDays: $nonPayableDays,
            // Phase 1: no statutory/earnings adjustments — gross equals net.
            grossSalary: $netSalary,
            netSalary: $netSalary,
            presentDays: $breakdown['present'],
            lateDays: $breakdown['late'],
            leaveDays: $breakdown['leave'],
            halfDayDays: $breakdown['half_day'],
            weeklyOffDays: $breakdown['weekly_off'],
            holidayDays: $breakdown['holiday'],
            absentDays: $breakdown['absent'],
            extraDays: $breakdown['extra'],
            salaryRecord: $salary,
            isSnapshot: false,
        );

        // Phase 2+/3 extension hooks (no-op when provider lists are empty).
        $this->collectEarnings($user, $monthStart, $result);
        $this->collectDeductions($user, $monthStart, $result);

        return $result;
    }

    /**
     * @return Collection<int, PayrollMonthResult>
     */
    public function calculateForTrackedUsers(Carbon $month): Collection
    {
        $monthStart = $month->copy()->startOfMonth();

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user))
            ->values();

        return $users
            ->map(fn (User $user): ?PayrollMonthResult => $this->calculateForUser($user, $monthStart))
            ->filter()
            ->values();
    }

    /**
     * @deprecated Use PayrollPayableDayPolicy::summarize() — kept for existing tests.
     *
     * @param  array<string, \App\Data\Workforce\AttendanceMatrixCell>  $cells
     * @return array<string, float|int>
     */
    public function classifyCells(array $cells): array
    {
        return $this->payableDayPolicy->summarize($cells);
    }

    /**
     * @return list<array{code: string, label: string, amount: float}>
     */
    private function collectEarnings(User $user, Carbon $month, PayrollMonthResult $result): array
    {
        $lines = [];
        foreach ($this->earningProviders as $provider) {
            $lines = [...$lines, ...$provider->earningsFor($user, $month, $result)];
        }

        return $lines;
    }

    /**
     * @return list<array{code: string, label: string, amount: float}>
     */
    private function collectDeductions(User $user, Carbon $month, PayrollMonthResult $result): array
    {
        $lines = [];
        foreach ($this->deductionProviders as $provider) {
            $lines = [...$lines, ...$provider->deductionsFor($user, $month, $result)];
        }

        return $lines;
    }
}
