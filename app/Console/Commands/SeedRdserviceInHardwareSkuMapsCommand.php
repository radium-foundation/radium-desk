<?php

namespace App\Console\Commands;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('desk:seed-rdservice-in-hardware-sku-maps {--dry-run : Preview without writing} {--apply : Persist missing rdservice_in channel_sku_maps rows}')]
#[Description('Owner-approved rdservice.in RIN hardware SKU maps. Never scheduled. Does not invent products.')]
class SeedRdserviceInHardwareSkuMapsCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Supply exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        $rows = config('hardware_fulfilment.rdservice_in.sku_maps', []);
        if (! is_array($rows) || $rows === []) {
            $this->error('hardware_fulfilment.rdservice_in.sku_maps is empty. Maps are not invented.');

            return self::FAILURE;
        }

        $preview = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'sku_maps' => 'SKU map row '.$index.' is invalid.',
                ]);
            }

            $modelId = (int) ($row['model_id'] ?? 0);
            $channelSku = trim((string) ($row['channel_sku'] ?? ''));
            $deskSku = strtoupper(trim((string) ($row['desk_sku'] ?? '')));
            if ($modelId < 1 || $channelSku === '' || $deskSku === '') {
                throw ValidationException::withMessages([
                    'sku_maps' => 'SKU map row '.$index.' is missing model_id, channel_sku, or desk_sku.',
                ]);
            }

            $product = InventoryProduct::query()->where('sku', $deskSku)->first();
            if ($product === null) {
                throw ValidationException::withMessages([
                    'desk_sku' => 'Desk inventory product '.$deskSku.' is missing. The map is not invented.',
                ]);
            }

            $existing = ChannelSkuMap::query()
                ->where('channel', StatutoryInvoiceChannel::RdServiceIn)
                ->where('model_id', $modelId)
                ->first();

            $preview[] = [
                'model_id' => $modelId,
                'channel_sku' => $channelSku,
                'desk_sku' => $deskSku,
                'inventory_product_id' => $product->id,
                'action' => $existing === null ? 'create' : 'unchanged',
            ];

            if (! $apply || $existing !== null) {
                continue;
            }

            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RdServiceIn,
                'model_id' => $modelId,
                'inventory_product_id' => $product->id,
                'channel_sku' => $channelSku,
                'catalog_sku' => $channelSku,
                'notes' => isset($row['notes']) ? (string) $row['notes'] : null,
            ]);
        }

        $this->line(json_encode([
            'ok' => true,
            'dry_run' => $dryRun,
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'rows' => $preview,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
