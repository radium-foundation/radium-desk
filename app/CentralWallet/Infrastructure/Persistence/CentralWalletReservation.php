<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\ReservationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'central_wallet_id',
        'caller_id',
        'amount',
        'currency',
        'state',
        'business_reference',
        'correlation_id',
        'source_reference',
        'metadata',
        'expires_at',
        'committed_at',
        'released_at',
        'expired_at',
        'ledger_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => ReservationState::class,
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
            'expired_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'central_wallet_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(CentralWalletLedgerEntry::class, 'ledger_entry_id');
    }

    public function isExpiredByTime(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
