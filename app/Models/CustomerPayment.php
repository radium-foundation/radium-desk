<?php

namespace App\Models;

use App\Enums\CustomerPaymentSource;
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
        'bank_name',
        'bank_branch',
        'payment_date',
        'notes',
        'recorded_by',
        'idempotency_key',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'source' => CustomerPaymentSource::class,
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
