<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentralCustomer extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'central_wallet_id',
        'status',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'central_wallet_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(CentralCustomerIdentityCredential::class, 'desk_customer_id');
    }
}
