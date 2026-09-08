<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'shipment_no',
        'commerce_order_id',
        'hardware_fulfilment_id',
        'provider',
        'status',
        'invoice_number',
        'serial_numbers',
        'pickup_location',
        'awb',
        'courier_id',
        'courier_name',
        'external_order_id',
        'external_shipment_id',
        'idempotency_key',
        'correlation_id',
        'create_snapshot',
        'failure_class',
        'attempts',
        'last_error',
        'provider_accepted_at',
        'awb_assigned_at',
        'pickup_requested_at',
        'label_url',
        'label_fetched_at',
        'manifest_id',
        'manifest_url',
        'manifest_generated_at',
        'last_reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'serial_numbers' => 'array',
            'create_snapshot' => 'array',
            'provider_accepted_at' => 'datetime',
            'awb_assigned_at' => 'datetime',
            'pickup_requested_at' => 'datetime',
            'label_fetched_at' => 'datetime',
            'manifest_generated_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function hardwareFulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class, 'hardware_fulfilment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('id');
    }

    public function isBound(): bool
    {
        return filled($this->external_order_id) && filled($this->external_shipment_id);
    }
}
