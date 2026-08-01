<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunLine extends Model
{
    protected $table = 'workforce_payroll_run_lines';

    protected $fillable = [
        'run_id',
        'user_id',
        'salary_revision_id',
        'monthly_salary_snapshot',
        'calendar_days',
        'day_rate',
        'payable_days',
        'non_payable_days',
        'gross_salary',
        'net_salary',
        'attendance_summary_json',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary_snapshot' => 'decimal:2',
            'day_rate' => 'decimal:4',
            'payable_days' => 'decimal:1',
            'non_payable_days' => 'decimal:1',
            'gross_salary' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'attendance_summary_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollMonthRun::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function salaryRevision(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalary::class, 'salary_revision_id');
    }
}
