<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentralWallet extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
    ];

    public function accountLinks(): HasMany
    {
        return $this->hasMany(CentralWalletAccountLink::class, 'central_wallet_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CentralWalletLedgerEntry::class, 'central_wallet_id');
    }
}
