<?php

namespace App\Models;

use App\Enums\WaitingReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentWaitingState extends Model
{
    protected $fillable = [
        'incident_id',
        'waiting_reason',
        'started_at',
        'sla_paused',
        'reminder_policy_key',
        'metadata',
        'next_action_at',
        'cleared_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'waiting_reason' => WaitingReason::class,
            'started_at' => 'datetime',
            'sla_paused' => 'boolean',
            'metadata' => 'array',
            'next_action_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function automationExecutions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'waiting_state_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cleared_at');
    }

    public function isActive(): bool
    {
        return $this->cleared_at === null;
    }

    public function reminderPolicyLabel(): ?string
    {
        if ($this->reminder_policy_key === null || $this->reminder_policy_key === '') {
            return null;
        }

        return config(
            "waiting_states.reminder_policies.{$this->reminder_policy_key}.label",
            str($this->reminder_policy_key)->headline()->toString(),
        );
    }
}
