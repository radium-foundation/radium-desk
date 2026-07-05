<?php

namespace App\Models;

use App\Enums\IraNotificationChannel;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IraNotification extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'channel',
        'title',
        'message',
        'payload',
        'status',
        'error_message',
        'sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'notification_type' => IraNotificationType::class,
            'channel' => IraNotificationChannel::class,
            'status' => IraNotificationStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
