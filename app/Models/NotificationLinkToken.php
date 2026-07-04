<?php

namespace App\Models;

use App\Enums\NotificationLinkSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationLinkToken extends Model
{
    protected $fillable = [
        'token',
        'incident_id',
        'order_id',
        'source',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => NotificationLinkSource::class,
            'expires_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(NotificationLinkClick::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
