<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\AuditActorType;
use Illuminate\Database\Eloquent\Model;

class CentralWalletAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'central_wallet_id',
        'actor_type',
        'actor_id',
        'correlation_id',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
