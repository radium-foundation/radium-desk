<?php

namespace App\Models;

use App\Enums\NotificationLinkSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLinkClick extends Model
{
    protected $fillable = [
        'notification_link_token_id',
        'incident_id',
        'order_id',
        'source',
        'clicked_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => NotificationLinkSource::class,
            'clicked_at' => 'datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(NotificationLinkToken::class, 'notification_link_token_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
