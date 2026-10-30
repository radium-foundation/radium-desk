<?php

namespace Tests\Support;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;

trait RadiumboxHardwareSkuMapTestCatalog
{
    protected function reloadHardwareFulfilmentConfigFromDisk(): void
    {
        $path = dirname(__DIR__, 2).'/config/hardware_fulfilment.php';
        config(['hardware_fulfilment' => require $path]);
    }

    /**
     * @return list<int>
     */
    protected function configuredRadiumboxModelIds(): array
    {
        $rows = config('hardware_fulfilment.radiumbox_com.sku_maps', []);

        return array_values(array_map(
            static fn (array $row): int => (int) $row['model_id'],
            is_array($rows) ? $rows : [],
        ));
    }

    /**
     * @return list<string>
     */
    protected function serializedDeskSkusForTests(): array
    {
        return [
            'RBHYP2003T',
            'RBBIOC600C',
            'RBAST300L1',
            'RBMFS500FP',
            'RBSMOOTHED',
            'RBBPVRIDL1',
            'RBPRAGATI2',
            'RBBPSR1200',
            'RBIBK2121U',
        ];
    }

    protected function seedDeskInventoryProductsForConfiguredMaps(): void
    {
        $serialized = array_fill_keys($this->serializedDeskSkusForTests(), true);

        foreach (config('hardware_fulfilment.radiumbox_com.sku_maps', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $deskSku = strtoupper(trim((string) ($row['desk_sku'] ?? '')));
            if ($deskSku === '') {
                continue;
            }

            InventoryProduct::query()->firstOrCreate(
                ['sku' => $deskSku],
                [
                    'name' => $deskSku,
                    'hsn_code' => '84716050',
                    'gst_percentage' => 18,
                    'unit_price' => 100,
                    'is_serialized' => isset($serialized[$deskSku]),
                    'is_active' => true,
                ],
            );
        }
    }

    protected function seedDecoyChannelMapsOnAlternateModelIds(): void
    {
        foreach ($this->configuredRadiumboxModelIds() as $modelId) {
            $product = InventoryProduct::query()->firstOrCreate(
                ['sku' => 'DECOY-'.$modelId],
                [
                    'name' => 'Decoy '.$modelId,
                    'hsn_code' => '85444299',
                    'gst_percentage' => 18,
                    'unit_price' => 100,
                    'is_serialized' => false,
                    'is_active' => true,
                ],
            );

            ChannelSkuMap::query()->firstOrCreate(
                [
                    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                    'model_id' => 9990 + $modelId,
                ],
                [
                    'inventory_product_id' => $product->id,
                    'catalog_sku' => 'EXISTING-'.$modelId,
                    'channel_sku' => '9990',
                ],
            );
        }
    }
}
