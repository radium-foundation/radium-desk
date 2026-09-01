<?php

namespace App\Models;

use App\Enums\InventorySerialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySerial extends Model
{
    protected $fillable = [
        'product_id',
        'variant_id',
        'serial_number',
        'branch_id',
        'status',
        'batch_code',
        'reserved_reservation_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventorySerialStatus::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryProductVariant::class, 'variant_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'reserved_reservation_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'serial_id');
    }

    public function saleAssignments(): HasMany
    {
        return $this->hasMany(InventorySaleSerial::class, 'serial_id');
    }
}
