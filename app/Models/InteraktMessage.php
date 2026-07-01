<?php

namespace App\Models;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use Illuminate\Database\Eloquent\Model;

class InteraktMessage extends Model
{
    protected $fillable = [
        'message_id',
        'customer_phone',
        'direction',
        'message_type',
        'text',
        'media_url',
        'template_name',
        'template_language',
        'delivery_status',
        'channel_failure_reason',
        'channel_error_code',
        'callback_data',
        'sent_at',
        'delivered_at',
        'read_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'direction' => InteraktMessageDirection::class,
            'delivery_status' => InteraktDeliveryStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
