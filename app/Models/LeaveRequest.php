<?php

namespace App\Models;

use App\Enums\LeaveAmendmentStatus;
use App\Enums\LeaveApprovalSource;
use App\Enums\LeaveDuration;
use App\Enums\LeavePayClass;
use App\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
        'approval_source',
        'policy_phase',
        'requires_manual_review',
        'is_manual_exception',
        'is_half_day_exception',
        'authorized_override',
        'authorized_override_by',
        'authorized_override_reason',
        'policy_effective_start_date',
        'policy_effective_end_date',
        'notice_calendar_days',
        'policy_metadata',
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
            'approval_source' => LeaveApprovalSource::class,
            'requires_manual_review' => 'boolean',
            'is_manual_exception' => 'boolean',
            'is_half_day_exception' => 'boolean',
            'authorized_override' => 'boolean',
            'policy_effective_start_date' => 'date',
            'policy_effective_end_date' => 'date',
            'policy_metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function policyEffectiveStart(): Carbon
    {
        return ($this->policy_effective_start_date ?? $this->start_date)->copy()->startOfDay();
    }

    public function policyEffectiveEnd(): Carbon
    {
        return ($this->policy_effective_end_date ?? $this->end_date)->copy()->startOfDay();
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
            ->where('status', LeaveAmendmentStatus::Pending->value)
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
