<?php

namespace App\Models;

use App\Enums\ShortAttendanceReviewDecision;
use App\Enums\ShortAttendanceReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkforceShortAttendanceReview extends Model
{
    protected $table = 'workforce_short_attendance_reviews';

    protected $fillable = [
        'user_id',
        'work_date',
        'status',
        'worked_minutes',
        'first_login_at',
        'last_activity_at',
        'last_logout_at',
        'session_count',
        'away_timeout_count',
        'had_auto_logout',
        'shift_label',
        'department',
        'manager_name',
        'calculated_reason',
        'evidence_snapshot',
        'previous_status',
        'decision',
        'new_status',
        'decision_reason',
        'decision_note',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => ShortAttendanceReviewStatus::class,
            'decision' => ShortAttendanceReviewDecision::class,
            'worked_minutes' => 'integer',
            'session_count' => 'integer',
            'away_timeout_count' => 'integer',
            'had_auto_logout' => 'boolean',
            'first_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'evidence_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === ShortAttendanceReviewStatus::PendingReview;
    }

    public function isDecided(): bool
    {
        return $this->status === ShortAttendanceReviewStatus::Decided;
    }
}
