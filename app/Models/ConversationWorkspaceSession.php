<?php

namespace App\Models;

use App\Enums\ConversationDisposition;
use App\Enums\ConversationNextAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationWorkspaceSession extends Model
{
    protected $fillable = [
        'incident_id',
        'call_id',
        'customer_name',
        'customer_need',
        'email',
        'whatsapp_same_number',
        'whatsapp_number',
        'brand',
        'model',
        'city',
        'source',
        'order_id_hint',
        'agent_notes',
        'disposition',
        'next_action',
        'current_step',
        'completed_fields',
        'skipped_fields',
        'status',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'whatsapp_same_number' => 'boolean',
            'disposition' => ConversationDisposition::class,
            'next_action' => ConversationNextAction::class,
            'completed_fields' => 'array',
            'skipped_fields' => 'array',
            'completed_at' => 'datetime',
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

    public function hasMandatoryLiveFields(): bool
    {
        return filled($this->customer_name) && filled($this->customer_need);
    }

    /**
     * @return array<string, mixed>
     */
    public function capturedPayload(): array
    {
        return [
            'customer_name' => $this->customer_name,
            'customer_need' => $this->customer_need,
            'email' => $this->email,
            'whatsapp_same_number' => $this->whatsapp_same_number,
            'whatsapp_number' => $this->whatsapp_number,
            'brand' => $this->brand,
            'model' => $this->model,
            'city' => $this->city,
            'source' => $this->source,
            'order_id' => $this->order_id_hint,
            'agent_notes' => $this->agent_notes,
            'disposition' => $this->disposition?->value,
            'next_action' => $this->next_action?->value,
        ];
    }
}
