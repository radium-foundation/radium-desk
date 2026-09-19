<?php

namespace App\Models;

use App\Enums\CatalogPriceSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogPriceSyncLog extends Model
{
    protected $fillable = [
        'inventory_product_id',
        'radiumbox_model_id',
        'requested_publish_price',
        'requested_gst_percentage',
        'idempotency_key',
        'requested_at',
        'applied_at',
        'status',
        'error_summary',
        'applied_publish_price',
        'applied_selling_price',
        'applied_liveprice',
        'applied_gst_percentage',
    ];

    protected function casts(): array
    {
        return [
            'inventory_product_id' => 'integer',
            'radiumbox_model_id' => 'integer',
            'requested_publish_price' => 'decimal:2',
            'requested_gst_percentage' => 'decimal:2',
            'requested_at' => 'datetime',
            'applied_at' => 'datetime',
            'status' => CatalogPriceSyncStatus::class,
            'applied_publish_price' => 'decimal:2',
            'applied_selling_price' => 'decimal:2',
            'applied_liveprice' => 'integer',
            'applied_gst_percentage' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
