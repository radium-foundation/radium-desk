<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class CentralWalletCeremonyProofConsumption extends Model
{
    protected $fillable = [
        'jti',
        'site_code',
        'local_user_id',
        'ceremony_attempt_id',
        'consumed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'ceremony_attempt_id' => 'string',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
