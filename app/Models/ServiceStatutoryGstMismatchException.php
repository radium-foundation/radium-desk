<?php

namespace App\Models;

use App\Enums\StatutoryInvoice\ServiceStatutoryGstMismatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceStatutoryGstMismatchException extends Model
{
    protected $fillable = [
        'commerce_order_id',
        'support_order_id',
        'incident_id',
        'status',
        'validation_reason',
        'original_buyer_gstin',
        'original_billing_state',
        'original_place_of_supply_state',
        'original_billing_pincode',
        'original_billing_address_structured',
        'original_billing_address',
        'corrected_buyer_gstin',
        'corrected_billing_state',
        'corrected_place_of_supply_state',
        'corrected_billing_pincode',
        'corrected_billing_address_structured',
        'customer_email',
        'customer_email_sent_at',
        'customer_response_at',
        'response_deadline_at',
        'resolved_at',
        'resolution_classification',
        'fallback_reason',
        'fallback_at',
        'statutory_invoice_id',
        'corrected_by_user_id',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceStatutoryGstMismatchStatus::class,
            'original_billing_address_structured' => 'array',
            'corrected_billing_address_structured' => 'array',
            'customer_email_sent_at' => 'datetime',
            'customer_response_at' => 'datetime',
            'response_deadline_at' => 'datetime',
            'resolved_at' => 'datetime',
            'fallback_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function supportOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'support_order_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }
}
