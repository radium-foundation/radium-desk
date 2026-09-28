<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletLedgerEntry extends Model
{
    protected $fillable = [
        'central_wallet_id',
        'entry_type',
        'amount',
        'currency',
        'status',
        'source_system',
        'source_reference',
        'correlation_id',
        'business_reference',
        'reservation_id',
        'metadata',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'status' => LedgerEntryStatus::class,
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'central_wallet_id');
    }
}
