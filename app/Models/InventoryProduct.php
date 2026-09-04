<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryProduct extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'hsn_code',
        'gst_percentage',
        'unit_price',
        'unit_cost',
        'is_serialized',
        'tracks_batch',
        'is_active',
        'device_model_id',
    ];

    protected function casts(): array
    {
        return [
            'gst_percentage' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'is_serialized' => 'boolean',
            'tracks_batch' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function deviceModel(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(InventoryProductVariant::class, 'product_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(InventorySerial::class, 'product_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'product_id');
    }

    public function priceFor(?InventoryProductVariant $variant = null): string
    {
        if ($variant !== null && $variant->unit_price !== null) {
            return (string) $variant->unit_price;
        }

        return (string) $this->unit_price;
    }
}
