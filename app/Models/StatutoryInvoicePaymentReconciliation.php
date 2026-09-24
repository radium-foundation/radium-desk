<?php

namespace App\Models;

use App\Enums\StatutoryInvoicePaymentBackfillOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryInvoicePaymentReconciliation extends Model
{
    public const SOURCE_HISTORICAL_POS_BACKFILL = 'historical_pos_backfill';

    protected $fillable = [
        'statutory_invoice_id',
        'outcome',
        'verified_amount',
        'payment_method',
        'payment_date',
        'bank_name',
        'bank_branch',
        'reference',
        'verification_remark',
        'source',
        'customer_payment_id',
        'payment_allocation_id',
        'recorded_by',
        'idempotency_key',
        'completed_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => StatutoryInvoicePaymentBackfillOutcome::class,
            'verified_amount' => 'decimal:2',
            'payment_date' => 'date',
            'completed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
