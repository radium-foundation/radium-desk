<?php

namespace App\Console\Commands;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('desk:seed-radiumbox-hardware-sku-maps {--dry-run : Preview without writing} {--apply : Persist missing radiumbox_com channel_sku_maps rows}')]
#[Description('Owner-approved radiumbox.com hardware SKU maps. Never scheduled. Does not invent products.')]
class SeedRadiumboxHardwareSkuMapsCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Supply exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        try {
            return $this->runSeed($dryRun, $apply);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first() ?: 'SKU map seed failed validation.');

            return self::FAILURE;
        }
    }

    private function runSeed(bool $dryRun, bool $apply): int
    {
        $rows = config('hardware_fulfilment.radiumbox_com.sku_maps', []);
        if (! is_array($rows) || $rows === []) {
            $this->error('hardware_fulfilment.radiumbox_com.sku_maps is empty. Maps are not invented.');

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
            $catalogSku = trim((string) ($row['catalog_sku'] ?? ''));
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
                ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
                ->where('model_id', $modelId)
                ->first();

            if ($existing !== null && (int) $existing->inventory_product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'sku_maps' => sprintf(
                        'Conflicting map for model_id %d already points to inventory_product_id %d.',
                        $modelId,
                        $existing->inventory_product_id,
                    ),
                ]);
            }

            $preview[] = [
                'model_id' => $modelId,
                'channel_sku' => $channelSku,
                'catalog_sku' => $catalogSku !== '' ? $catalogSku : null,
                'desk_sku' => $deskSku,
                'inventory_product_id' => $product->id,
                'action' => $existing === null ? 'create' : 'unchanged',
            ];

            if (! $apply || $existing !== null) {
                continue;
            }

            ChannelSkuMap::query()->create([
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
                'inventory_product_id' => $product->id,
                'channel_sku' => $channelSku,
                'catalog_sku' => $catalogSku !== '' ? $catalogSku : null,
                'notes' => isset($row['notes']) ? (string) $row['notes'] : null,
            ]);
        }

        $this->line(json_encode([
            'ok' => true,
            'dry_run' => $dryRun,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'rows' => $preview,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
