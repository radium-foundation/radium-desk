<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollMonthLock extends Model
{
    protected $table = 'workforce_payroll_month_locks';

    protected $fillable = [
        'month',
        'locked_by',
        'locked_at',
        'unlocked_by',
        'unlocked_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function isCurrentlyLocked(): bool
    {
        return $this->unlocked_at === null;
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
