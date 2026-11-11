<?php

namespace App\Models;

use App\Enums\OemWarrantyLookupSessionStatus;
use App\Enums\OemWarrantyProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OemWarrantyLookupSession extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected $fillable = [
        'public_id',
        'order_id',
        'user_id',
        'provider',
        'serial_number',
        'status',
        'handoff_token_hash',
        'expires_at',
        'draft_submitted_at',
        'consumed_at',
        'draft_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => OemWarrantyLookupSessionStatus::class,
            'provider' => OemWarrantyProvider::class,
            'expires_at' => 'datetime',
            'draft_submitted_at' => 'datetime',
            'consumed_at' => 'datetime',
            'draft_payload' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function check(): HasOne
    {
        return $this->hasOne(OemWarrantyCheck::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
