<?php

namespace App\Models;

use App\Enums\InventorySerialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentLine extends Model
{
    protected $fillable = [
        'adjustment_id',
        'product_id',
        'variant_id',
        'serial_id',
        'qty_delta',
        'from_status',
        'to_status',
    ];

    protected function casts(): array
    {
        return [
            'qty_delta' => 'integer',
            'from_status' => InventorySerialStatus::class,
            'to_status' => InventorySerialStatus::class,
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id');
    }
}
