<?php

namespace App\Models;

use App\Enums\GoodsReceiptSerialValidationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptSerial extends Model
{
    protected $fillable = [
        'goods_receipt_id',
        'goods_receipt_item_id',
        'purchase_order_id',
        'vendor_id',
        'product_id',
        'variant_id',
        'serial_number',
        'validation_status',
        'validation_message',
        'inventory_serial_id',
    ];

    protected function casts(): array
    {
        return [
            'validation_status' => GoodsReceiptSerialValidationStatus::class,
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function inventorySerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }
}
