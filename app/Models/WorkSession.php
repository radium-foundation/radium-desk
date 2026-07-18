<?php

namespace App\Models;

use App\Enums\WorkSessionEndReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSession extends Model
{
    protected $fillable = [
        'user_id',
        'work_date',
        'login_at',
        'logout_at',
        'ended_reason',
        'session_duration_seconds',
        'active_duration_seconds',
        'idle_duration_seconds',
        'lunch_duration_seconds',
        'break_duration_seconds',
        'extra_idle_duration_seconds',
        'overtime_seconds',
        'break_allowance_seconds',
        'expected_working_minutes',
        'on_time_login',
        'cases_handled_count',
        'communication_events_count',
        'resolution_events_count',
        'current_order_id',
        'current_incident_id',
        'last_business_action',
        'last_order_viewed_at',
        'last_incident_viewed_at',
        'last_business_action_at',
        'last_activity_at',
        'last_tick_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
            'ended_reason' => WorkSessionEndReason::class,
            'session_duration_seconds' => 'integer',
            'active_duration_seconds' => 'integer',
            'idle_duration_seconds' => 'integer',
            'lunch_duration_seconds' => 'integer',
            'break_duration_seconds' => 'integer',
            'extra_idle_duration_seconds' => 'integer',
            'overtime_seconds' => 'integer',
            'break_allowance_seconds' => 'integer',
            'expected_working_minutes' => 'integer',
            'on_time_login' => 'boolean',
            'cases_handled_count' => 'integer',
            'communication_events_count' => 'integer',
            'resolution_events_count' => 'integer',
            'current_order_id' => 'integer',
            'current_incident_id' => 'integer',
            'last_order_viewed_at' => 'datetime',
            'last_incident_viewed_at' => 'datetime',
            'last_business_action_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_tick_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'current_order_id');
    }

    public function currentIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'current_incident_id');
    }

    public function isOpen(): bool
    {
        return $this->logout_at === null;
    }
}
