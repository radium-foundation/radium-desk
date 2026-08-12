<?php

namespace App\Models;

use App\Enums\LeaveAmendmentSource;
use App\Enums\LeaveAmendmentStatus;
use App\Enums\LeaveAmendmentType;
use App\Enums\LeaveDuration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestAmendment extends Model
{
    protected $fillable = [
        'leave_request_id',
        'type',
        'source',
        'requested_by',
        'previous_start_date',
        'previous_end_date',
        'previous_duration',
        'proposed_start_date',
        'proposed_end_date',
        'proposed_duration',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => LeaveAmendmentType::class,
            'source' => LeaveAmendmentSource::class,
            'status' => LeaveAmendmentStatus::class,
            'previous_start_date' => 'date',
            'previous_end_date' => 'date',
            'proposed_start_date' => 'date',
            'proposed_end_date' => 'date',
            'previous_duration' => LeaveDuration::class,
            'proposed_duration' => LeaveDuration::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === LeaveAmendmentStatus::Pending;
    }

    public function isCancellation(): bool
    {
        return $this->type === LeaveAmendmentType::Cancellation;
    }
}
