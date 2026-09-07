<?php

namespace App\Models;

use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelSkuMap extends Model
{
    protected $fillable = [
        'channel',
        'model_id',
        'inventory_product_id',
        'catalog_sku',
        'channel_sku',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::class,
            'model_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
