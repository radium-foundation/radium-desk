<?php

namespace App\Console\Commands;

use App\Services\HardwareFulfilment\HardwareShiprocketTrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shipping:sync-shiprocket-tracking {--limit=25 : Max eligible shipments this run}')]
#[Description('Read-only Shiprocket track ingest for AWB-assigned Desk shipments')]
class SyncShiprocketTrackingCommand extends Command
{
    public function handle(HardwareShiprocketTrackingService $tracking): int
    {
        if (! (bool) config('shipping.tracking.sync_enabled', true)) {
            $this->line('Shiprocket tracking sync disabled.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $stats = $tracking->syncEligible($limit);

        $this->line(sprintf(
            'scanned=%d changed=%d skipped=%d failed=%d',
            $stats['scanned'],
            $stats['changed'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
