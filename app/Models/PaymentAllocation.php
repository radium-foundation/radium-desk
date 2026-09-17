<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'customer_payment_id',
        'statutory_invoice_id',
        'amount',
        'idempotency_key',
        'allocated_by',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocated_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
