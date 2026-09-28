<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class CentralWalletIdempotencyRecord extends Model
{
    protected $fillable = [
        'caller_id',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'response_body_hash',
        'resource_type',
        'resource_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
