<?php

namespace App\Models;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationExecution extends Model
{
    protected $fillable = [
        'waiting_state_id',
        'policy_key',
        'schedule_step',
        'action_type',
        'action_key',
        'channel',
        'status',
        'idempotency_key',
        'external_id',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => AutomationPolicyActionType::class,
            'status' => AutomationExecutionStatus::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function waitingState(): BelongsTo
    {
        return $this->belongsTo(IncidentWaitingState::class, 'waiting_state_id');
    }
}
