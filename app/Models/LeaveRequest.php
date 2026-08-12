<?php

namespace App\Models;

use App\Enums\LeaveDuration;
use App\Enums\LeavePayClass;
use App\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaveRequest extends Model
{
    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'reason',
        'duration',
        'pay_class',
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
            'pay_class' => LeavePayClass::class,
            'status' => LeaveRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function isHalfDay(): bool
    {
        return $this->duration === LeaveDuration::HalfDay;
    }

    public function isUnpaid(): bool
    {
        return $this->pay_class === LeavePayClass::Unpaid;
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveRequest $leaveRequest): void {
            if ($leaveRequest->duration === null) {
                $leaveRequest->duration = LeaveDuration::FullDay;
            }
            if ($leaveRequest->pay_class === null) {
                $leaveRequest->pay_class = LeavePayClass::Paid;
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

    public function amendments(): HasMany
    {
        return $this->hasMany(LeaveRequestAmendment::class);
    }

    public function pendingAmendment(): HasOne
    {
        return $this->hasOne(LeaveRequestAmendment::class)
            ->where('status', \App\Enums\LeaveAmendmentStatus::Pending->value)
            ->latestOfMany();
    }

    public function hasPendingAmendment(): bool
    {
        return $this->pendingAmendment()->exists();
    }

    public function isApproved(): bool
    {
        return $this->status === LeaveRequestStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === LeaveRequestStatus::Pending;
    }

    public function isCancelled(): bool
    {
        return $this->status === LeaveRequestStatus::Cancelled;
    }
}
