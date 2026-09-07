<?php

namespace App\Models;

use App\Enums\HardwareFulfilmentSerialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareFulfilmentSerial extends Model
{
    protected $fillable = [
        'hardware_fulfilment_id',
        'commerce_order_item_id',
        'line_no',
        'position',
        'inventory_serial_id',
        'serial_number',
        'status',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'position' => 'integer',
            'status' => HardwareFulfilmentSerialStatus::class,
            'allocated_at' => 'datetime',
        ];
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class, 'hardware_fulfilment_id');
    }

    public function commerceOrderItem(): BelongsTo
    {
        return $this->belongsTo(CommerceOrderItem::class);
    }

    public function inventorySerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }
}
