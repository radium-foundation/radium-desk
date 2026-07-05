<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMemberWorkSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'work_start_time',
        'work_end_time',
        'lunch_start_time',
        'lunch_end_time',
        'short_break_count',
        'short_break_minutes',
        'weekly_off_days',
    ];

    protected function casts(): array
    {
        return [
            'short_break_count' => 'integer',
            'short_break_minutes' => 'integer',
            'weekly_off_days' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
