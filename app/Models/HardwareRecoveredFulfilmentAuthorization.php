<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareRecoveredFulfilmentAuthorization extends Model
{
    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'channel',
        'source_type',
        'source_id',
        'commerce_order_id',
        'commerce_order_no',
        'purpose',
        'status',
        'authorized_at',
        'authorized_by',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_AUTHORIZED && $this->revoked_at === null;
    }
}
