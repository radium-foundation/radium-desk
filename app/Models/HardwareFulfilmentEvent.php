<?php

namespace App\Models;

use App\Enums\HardwareFulfilmentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareFulfilmentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'hardware_fulfilment_id',
        'from_state',
        'to_state',
        'actor_type',
        'actor_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_state' => HardwareFulfilmentState::class,
            'to_state' => HardwareFulfilmentState::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class, 'hardware_fulfilment_id');
    }
}
