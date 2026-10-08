<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterBranchTransactionLine extends Model
{
    protected $fillable = [
        'inter_branch_transaction_id',
        'product_id',
        'variant_id',
        'serial_id',
        'qty',
        'unit_price',
        'gst_percentage',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InterBranchTransaction::class, 'inter_branch_transaction_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryProductVariant::class, 'variant_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id');
    }
}
