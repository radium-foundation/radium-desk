<?php

namespace App\Models;

use App\Enums\BonvoiceCallAlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonvoiceCallAlert extends Model
{
    protected $fillable = [
        'bonvoice_call_event_id',
        'call_id',
        'user_id',
        'alert_type',
        'customer_phone',
        'order_id',
        'incident_id',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'alert_type' => BonvoiceCallAlertType::class,
            'notified_at' => 'datetime',
        ];
    }

    public function callEvent(): BelongsTo
    {
        return $this->belongsTo(BonvoiceCallEvent::class, 'bonvoice_call_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
