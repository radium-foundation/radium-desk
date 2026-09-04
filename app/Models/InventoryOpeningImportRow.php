<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryOpeningImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'sheet',
        'row_number',
        'fingerprint',
        'applied_identity',
        'sku',
        'variant_sku',
        'branch_code',
        'serial_number',
        'qty',
        'status',
        'issues',
        'product_id',
        'serial_id',
        'movement_id',
    ];

    protected function casts(): array
    {
        return [
            'issues' => 'array',
            'qty' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryOpeningImportBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }
}
