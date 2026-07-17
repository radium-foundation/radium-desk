<?php

namespace App\Models;

use App\Enums\AttendanceDayStatus;
use App\Enums\WorkCalendarDayStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkforceAttendanceDay extends Model
{
    protected $fillable = [
        'user_id',
        'work_date',
        'status',
        'calendar_status',
        'is_working_day',
        'is_company_holiday',
        'is_on_leave',
        'has_schedule',
        'first_login_at',
        'last_logout_at',
        'on_time_login',
        'minutes_late',
        'session_count',
        'session_duration_seconds',
        'active_duration_seconds',
        'idle_duration_seconds',
        'lunch_duration_seconds',
        'break_duration_seconds',
        'extra_idle_duration_seconds',
        'overtime_seconds',
        'away_timeout_count',
        'manual_logout_count',
        'expected_working_minutes',
        'finalized_at',
        'computed_at',
        'source_version',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => AttendanceDayStatus::class,
            'calendar_status' => WorkCalendarDayStatus::class,
            'is_working_day' => 'boolean',
            'is_company_holiday' => 'boolean',
            'is_on_leave' => 'boolean',
            'has_schedule' => 'boolean',
            'first_login_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'on_time_login' => 'boolean',
            'minutes_late' => 'integer',
            'session_count' => 'integer',
            'session_duration_seconds' => 'integer',
            'active_duration_seconds' => 'integer',
            'idle_duration_seconds' => 'integer',
            'lunch_duration_seconds' => 'integer',
            'break_duration_seconds' => 'integer',
            'extra_idle_duration_seconds' => 'integer',
            'overtime_seconds' => 'integer',
            'away_timeout_count' => 'integer',
            'manual_logout_count' => 'integer',
            'expected_working_minutes' => 'integer',
            'finalized_at' => 'datetime',
            'computed_at' => 'datetime',
            'source_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
