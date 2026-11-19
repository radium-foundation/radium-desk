<?php

namespace App\Models;

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceInvoiceGstClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceOrderBuyerGstVerification extends Model
{
    protected $fillable = [
        'commerce_order_id',
        'submitted_gstin',
        'verification_status',
        'invoice_classification',
        'verification_source',
        'provider_correlation_id',
        'verified_at',
        'legal_name',
        'registration_status',
        'review_reason',
        'submitted_billing_address_structured',
        'submitted_shipping_address_structured',
        'verified_registration_address_structured',
        'bill_to_address_structured',
        'provider_payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => BuyerGstVerificationStatus::class,
            'invoice_classification' => CommerceInvoiceGstClassification::class,
            'verified_at' => 'datetime',
            'submitted_billing_address_structured' => 'array',
            'submitted_shipping_address_structured' => 'array',
            'verified_registration_address_structured' => 'array',
            'bill_to_address_structured' => 'array',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }
}
