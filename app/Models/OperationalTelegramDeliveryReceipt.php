<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalTelegramDeliveryReceipt extends Model
{
    public const KIND_AW_ORDER_DAILY = 'aw_order_daily';

    protected $fillable = [
        'delivery_kind',
        'report_date',
        'user_id',
        'telegram_message_id',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
