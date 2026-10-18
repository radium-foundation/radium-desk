<?php

namespace App\Models;

use App\Enums\InventoryAvailabilitySyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAvailabilitySyncLog extends Model
{
    protected $fillable = [
        'inventory_product_id',
        'radiumbox_model_id',
        'available_qty',
        'inventory_tracking',
        'idempotency_key',
        'requested_at',
        'applied_at',
        'status',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'inventory_product_id' => 'integer',
            'radiumbox_model_id' => 'integer',
            'available_qty' => 'integer',
            'requested_at' => 'datetime',
            'applied_at' => 'datetime',
            'status' => InventoryAvailabilitySyncStatus::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
