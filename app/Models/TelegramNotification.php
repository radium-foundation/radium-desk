<?php

namespace App\Models;

use App\Enums\TelegramNotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TelegramNotification extends Model
{
    protected $fillable = [
        'user_id',
        'notifiable_type',
        'notifiable_id',
        'event',
        'telegram_chat_id',
        'message',
        'payload',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => TelegramNotificationStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
