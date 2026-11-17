<?php

namespace App\Jobs\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareStuckOrdersSnapshotRefresher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshHardwareStuckOrdersSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return 'hardware-stuck-orders-snapshot';
    }

    public function handle(HardwareStuckOrdersSnapshotRefresher $refresher): void
    {
        $refresher->refreshAndBroadcast();
    }
}
