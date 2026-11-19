<?php

namespace App\Models;

use App\Enums\BuyerGstB2cConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceOrderBuyerGstB2cConversion extends Model
{
    protected $fillable = [
        'commerce_order_id',
        'statutory_invoice_id',
        'commerce_order_buyer_gst_verification_id',
        'submitted_gstin',
        'verification_status',
        'registration_status',
        'review_reason',
        'provider_payload_hash',
        'status',
        'authorized_by',
        'authorized_at',
        'decision_reason',
        'invoice_remark',
    ];

    protected function casts(): array
    {
        return [
            'status' => BuyerGstB2cConversionStatus::class,
            'authorized_at' => 'datetime',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function buyerGstVerification(): BelongsTo
    {
        return $this->belongsTo(CommerceOrderBuyerGstVerification::class, 'commerce_order_buyer_gst_verification_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class);
    }
}
