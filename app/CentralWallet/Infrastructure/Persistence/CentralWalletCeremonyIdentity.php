<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletCeremonyIdentity extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'site_code',
        'phone_e164_hash',
        'central_wallet_id',
        'first_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'first_verified_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'central_wallet_id');
    }
}
