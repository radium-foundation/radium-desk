<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockBalance extends Model
{
    protected $fillable = [
        'balance_key',
        'product_id',
        'variant_id',
        'branch_id',
        'available_qty',
        'reserved_qty',
    ];

    protected function casts(): array
    {
        return [
            'available_qty' => 'integer',
            'reserved_qty' => 'integer',
        ];
    }

    public static function keyFor(int $productId, ?int $variantId, int $branchId): string
    {
        return $productId.':'.($variantId ?? 0).':'.$branchId;
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
}
