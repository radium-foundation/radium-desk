<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletAccountLink extends Model
{
    protected $fillable = [
        'central_wallet_id',
        'site_code',
        'local_user_id',
        'status',
        'verification_method',
        'linked_at',
        'revoked_at',
        'created_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountLinkStatus::class,
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'central_wallet_id');
    }
}
