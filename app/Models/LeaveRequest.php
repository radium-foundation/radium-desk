<?php

namespace App\Models;

use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'reason',
        'duration',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'duration' => LeaveDuration::class,
            'status' => LeaveRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function isHalfDay(): bool
    {
        return $this->duration === LeaveDuration::HalfDay;
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveRequest $leaveRequest): void {
            if ($leaveRequest->duration === null) {
                $leaveRequest->duration = LeaveDuration::FullDay;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
