<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralCustomerIdentityCredential extends Model
{
    protected $fillable = [
        'desk_customer_id',
        'credential_type',
        'provider',
        'subject_hash',
        'verified_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'credential_type' => CustomerIdentityCredentialType::class,
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CentralCustomer::class, 'desk_customer_id');
    }
}
