<?php

namespace App\Services\Workforce\Payroll;

use App\Models\EmployeeSalary;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Append-only salary revisions. Never mutates historical rows.
 * Current / month resolution = latest effective_from (optionally active).
 */
class EmployeeSalaryService
{
    /**
     * @return Collection<int, EmployeeSalary>
     */
    public function listAll(): Collection
    {
        return EmployeeSalary::query()
            ->with('user')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Latest active revision with effective_from on or before month end.
     * Future revisions do not affect past months.
     */
    public function salaryForMonth(User $user, Carbon $month): ?EmployeeSalary
    {
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        return EmployeeSalary::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereDate('effective_from', '<=', $monthEnd)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Latest active revision overall (current compensation).
     */
    public function currentRevision(User $user): ?EmployeeSalary
    {
        return EmployeeSalary::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array{user_id: int, monthly_salary: float|string, effective_from: string, is_active?: bool}  $attributes
     */
    public function create(array $attributes): EmployeeSalary
    {
        return EmployeeSalary::query()->create([
            'user_id' => (int) $attributes['user_id'],
            'monthly_salary' => $attributes['monthly_salary'],
            'effective_from' => $attributes['effective_from'],
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ]);
    }

    /**
     * Append a new revision for the same employee. Never updates the prior row.
     *
     * @param  array{monthly_salary: float|string, effective_from: string, is_active?: bool}  $attributes
     */
    public function revise(EmployeeSalary $fromRevision, array $attributes): EmployeeSalary
    {
        return $this->create([
            'user_id' => (int) $fromRevision->user_id,
            'monthly_salary' => $attributes['monthly_salary'],
            'effective_from' => $attributes['effective_from'],
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ]);
    }
}
