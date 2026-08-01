<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollMonthRun extends Model
{
    protected $table = 'workforce_payroll_month_runs';

    protected $fillable = [
        'month',
        'status',
        'finalized_at',
        'finalized_by',
        'calculation_version',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'status' => PayrollRunStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollRunStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->status === PayrollRunStatus::Finalized;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class, 'run_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
