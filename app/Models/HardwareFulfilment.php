<?php

namespace App\Models;

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'parcel_snapshot',
        'shipping_country_overlay',
        'shipping_country_overlay_at',
        'shipping_country_overlay_by_user_id',
        'shipping_country_overlay_context',
        'courier_options_snapshot',
        'courier_options_fingerprint',
        'courier_options_fetched_at',
        'courier_options_expires_at',
        'selected_courier_id',
        'selected_courier_name',
        'selected_courier_at',
        'selected_courier_by_user_id',
        'ready_for_pickup_at',
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
            'parcel_snapshot' => 'array',
            'shipping_country_overlay_at' => 'datetime',
            'shipping_country_overlay_context' => 'array',
            'courier_options_snapshot' => 'array',
            'courier_options_fetched_at' => 'datetime',
            'courier_options_expires_at' => 'datetime',
            'selected_courier_at' => 'datetime',
            'ready_for_pickup_at' => 'datetime',
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

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'hardware_fulfilment_id');
    }

    public function shippingCountryOverlayBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipping_country_overlay_by_user_id');
    }

    public function selectedCourierBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_courier_by_user_id');
    }

    public function packageEvidences(): HasMany
    {
        return $this->hasMany(HardwareFulfilmentPackageEvidence::class)->orderBy('id');
    }

    public function supportOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'support_order_id');
    }
}
