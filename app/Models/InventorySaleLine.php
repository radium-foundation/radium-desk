<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySaleLine extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'variant_id',
        'qty',
        'unit_price',
        'gst_percentage',
        'discount',
        'tax',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryProductVariant::class, 'variant_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(InventorySaleSerial::class, 'sale_line_id');
    }

    public function catalogLabel(): string
    {
        $sku = (string) ($this->product?->sku ?? '');
        $name = (string) ($this->product?->name ?? '');
        if ($this->variant === null) {
            return trim($sku.' — '.$name);
        }

        return trim($sku.' / '.$this->variant->sku.' — '.$name.' ('.$this->variant->name.')');
    }
}
