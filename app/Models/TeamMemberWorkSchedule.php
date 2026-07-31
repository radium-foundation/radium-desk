<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TeamMemberWorkSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'effective_from',
        'effective_to',
        'work_start_time',
        'work_end_time',
        'lunch_start_time',
        'lunch_end_time',
        'short_break_count',
        'short_break_minutes',
        'weekly_off_days',
        'expected_working_minutes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'short_break_count' => 'integer',
            'short_break_minutes' => 'integer',
            'weekly_off_days' => 'array',
            'expected_working_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TeamMemberWorkSchedule $schedule): void {
            // Tests and legacy creates without an effective_from must still cover
            // historical work_dates. Prefer an open-ended past start over "today".
            if ($schedule->effective_from === null) {
                $schedule->effective_from = '2000-01-01';
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Schedule version active on calendar date D (inclusive window).
     *
     * @param  Builder<TeamMemberWorkSchedule>  $query
     * @return Builder<TeamMemberWorkSchedule>
     */
    public function scopeEffectiveOn(Builder $query, Carbon|string $date): Builder
    {
        $day = Carbon::parse($date)->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $day)
            ->where(function (Builder $inner) use ($day): void {
                $inner->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $day);
            });
    }

    /**
     * Open-ended current version (effective_to is null).
     *
     * @param  Builder<TeamMemberWorkSchedule>  $query
     * @return Builder<TeamMemberWorkSchedule>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'work_start_time' => (string) $this->work_start_time,
            'work_end_time' => (string) $this->work_end_time,
            'lunch_start_time' => $this->lunch_start_time !== null ? (string) $this->lunch_start_time : null,
            'lunch_end_time' => $this->lunch_end_time !== null ? (string) $this->lunch_end_time : null,
            'short_break_count' => (int) $this->short_break_count,
            'short_break_minutes' => (int) $this->short_break_minutes,
            'weekly_off_days' => $this->weekly_off_days,
            'expected_working_minutes' => $this->expected_working_minutes,
            'created_by' => $this->created_by,
        ];
    }
}
