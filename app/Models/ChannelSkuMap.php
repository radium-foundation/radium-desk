<?php

namespace App\Models;

use App\Enums\StatutoryInvoiceChannel;
use App\Services\Inventory\InventoryAvailabilitySyncService;
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

    protected static function booted(): void
    {
        static::created(function (ChannelSkuMap $map): void {
            self::dispatchStorefrontAvailabilitySyncForMap($map);
        });

        static::updated(function (ChannelSkuMap $map): void {
            if ($map->wasChanged(['channel', 'model_id', 'inventory_product_id'])) {
                self::dispatchStorefrontAvailabilitySyncForMap($map);
            }
        });
    }

    private static function dispatchStorefrontAvailabilitySyncForMap(ChannelSkuMap $map): void
    {
        $channel = $map->channel instanceof StatutoryInvoiceChannel
            ? $map->channel
            : StatutoryInvoiceChannel::tryFrom((string) $map->channel);

        if ($channel !== StatutoryInvoiceChannel::RadiumBoxCom) {
            return;
        }

        $product = $map->product;
        if ($product === null) {
            return;
        }

        app(InventoryAvailabilitySyncService::class)->dispatchIfEligible($product);
    }
}
