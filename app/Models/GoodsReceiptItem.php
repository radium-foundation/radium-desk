<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'variant_id',
        'quantity_received',
        'quantity_damaged',
        'quantity_short',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'quantity_damaged' => 'integer',
            'quantity_short' => 'integer',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(GoodsReceiptSerial::class, 'goods_receipt_item_id');
    }
}
