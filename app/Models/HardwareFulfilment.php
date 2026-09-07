<?php

namespace App\Models;

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HardwareFulfilment extends Model
{
    protected $fillable = [
        'commerce_order_id',
        'channel',
        'source_type',
        'source_id',
        'idempotency_key',
        'state',
        'support_order_id',
        'cashfree_payment_id',
        'payment_reference',
        'fulfilment_branch_id',
        'issuer_location',
        'statutory_invoice_id',
        'shipment_id',
        'shipment_no',
        'awb',
        'provider_shipment_id',
        'provider_awb',
        'retry_count',
        'last_error',
        'metadata',
        'ingested_at',
        'paid_recognized_at',
        'ready_at',
        'serials_allocated_at',
        'invoice_issued_at',
        'shipment_created_at',
        'awb_assigned_at',
        'shipped_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::class,
            'state' => HardwareFulfilmentState::class,
            'retry_count' => 'integer',
            'metadata' => 'array',
            'ingested_at' => 'datetime',
            'paid_recognized_at' => 'datetime',
            'ready_at' => 'datetime',
            'serials_allocated_at' => 'datetime',
            'invoice_issued_at' => 'datetime',
            'shipment_created_at' => 'datetime',
            'awb_assigned_at' => 'datetime',
            'shipped_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(HardwareFulfilmentEvent::class)->orderBy('id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(HardwareFulfilmentSerial::class)->orderBy('line_no')->orderBy('position');
    }

    public function paymentEvidence(): HasMany
    {
        return $this->hasMany(HardwareFulfilmentPaymentEvidence::class)->orderBy('id');
    }

    public function fulfilmentBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'fulfilment_branch_id');
    }
}
