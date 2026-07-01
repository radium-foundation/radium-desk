<?php

namespace App\Models;

use App\Enums\OutboxEventStatus;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'processed_at',
        'last_error',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxEventStatus::class,
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
