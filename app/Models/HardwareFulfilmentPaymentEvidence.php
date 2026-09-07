<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareFulfilmentPaymentEvidence extends Model
{
    protected $table = 'hardware_fulfilment_payment_evidence';

    protected $fillable = [
        'source_id',
        'hardware_fulfilment_id',
        'commerce_order_id',
        'support_order_id',
        'cashfree_payment_id',
        'merchant_order_id',
        'cf_order_id',
        'gateway_order_id',
        'gateway_payment_id',
        'bank_reference',
        'payment_status',
        'verified',
        'payment_amount',
        'payment_method',
        'paid_at',
        'cashfree_webhook_log_id',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'payment_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class, 'hardware_fulfilment_id');
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }
}
