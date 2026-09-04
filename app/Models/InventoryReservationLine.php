<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservationLine extends Model
{
    protected $fillable = [
        'reservation_id',
        'product_id',
        'variant_id',
        'serial_id',
        'qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'reservation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryProductVariant::class, 'variant_id');
    }
}
