<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonvoiceCallEvent extends Model
{
    protected $fillable = [
        'call_id',
        'leg',
        'customer_phone',
        'source_number',
        'destination_number',
        'display_number',
        'direction',
        'status',
        'agent_status',
        'call_type',
        'account_id',
        'data_source',
        'event_id',
        'callback_parent_id',
        'callback_params',
        'started_at',
        'payload',
        'webhook_log_id',
    ];

    protected function casts(): array
    {
        return [
            'callback_params' => 'array',
            'payload' => 'array',
            'started_at' => 'datetime',
        ];
    }

    public function webhookLog(): BelongsTo
    {
        return $this->belongsTo(BonvoiceWebhookLog::class, 'webhook_log_id');
    }
}
