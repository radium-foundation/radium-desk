<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialServiceRestoration extends Model
{
    protected $fillable = [
        'order_id',
        'refund_request_id',
        'finance_verified',
        'wallet_reversed_externally',
        'wallet_reversal_reference',
        'finance_note',
        'verified_by_user_id',
        'verified_at',
        'recorded_by_user_id',
        'recorded_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'finance_verified' => 'boolean',
            'wallet_reversed_externally' => 'boolean',
            'verified_at' => 'datetime',
            'recorded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
