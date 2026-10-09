<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletRefundMigrationResolution extends Model
{
    protected $fillable = [
        'refund_id',
        'resolution_type',
        'source_application',
        'source_wallet_id',
        'source_local_user_id',
        'desk_customer_id',
        'cwid',
        'owner_approval_ref',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CentralCustomer::class, 'desk_customer_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'cwid');
    }
}
