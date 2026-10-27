<?php

namespace App\Console\Commands;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Services\Inventory\InventoryAvailabilitySyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radiumbox:backfill-catalog-availability
    {--dry-run : List eligible maps without dispatching sync jobs}
    {--limit= : Maximum number of maps to dispatch}')]
#[Description('Dispatch initial RadiumBox availability snapshots for Owner-approved radiumbox_com channel_sku_maps (requires RADIUMBOX_AVAILABILITY_SYNC_ENABLED)')]
class BackfillRadiumboxCatalogAvailabilityCommand extends Command
{
    public function __construct(
        private readonly InventoryAvailabilitySyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? max(1, (int) $limit) : null;

        if (! config('radiumbox.availability_sync.enabled')) {
            $this->warn('RADIUMBOX_AVAILABILITY_SYNC_ENABLED is false; no jobs will be dispatched.');

            if (! $dryRun) {
                return self::SUCCESS;
            }
        }

        $dispatched = 0;
        $scanned = 0;

        ChannelSkuMap::query()
            ->with('product')
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
            ->orderBy('id')
            ->chunkById(50, function ($maps) use ($dryRun, $limit, &$dispatched, &$scanned): bool {
                foreach ($maps as $map) {
                    if ($limit !== null && $dispatched >= $limit) {
                        return false;
                    }

                    $scanned++;
                    $product = $map->product;
                    if ($product === null || ! $product->is_active) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            'Would dispatch availability sync for desk_product_id=%d model_id=%d sku=%s',
                            $product->id,
                            (int) $map->model_id,
                            $product->sku,
                        ));
                        $dispatched++;

                        continue;
                    }

                    $this->syncService->dispatchIfEligible($product);
                    $dispatched++;
                }

                return true;
            });

        $this->info(sprintf(
            'RadiumBox catalog availability backfill %s: scanned=%d dispatched=%d.',
            $dryRun ? 'dry run' : 'completed',
            $scanned,
            $dispatched,
        ));

        return self::SUCCESS;
    }
}
