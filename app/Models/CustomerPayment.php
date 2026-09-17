<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPayment extends Model
{
    protected $fillable = [
        'payment_number',
        'customer_id',
        'amount',
        'method',
        'reference',
        'payment_date',
        'notes',
        'recorded_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(InventoryCustomer::class, 'customer_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'customer_payment_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
