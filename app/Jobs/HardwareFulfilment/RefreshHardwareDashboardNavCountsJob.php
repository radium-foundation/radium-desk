<?php

namespace App\Jobs\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareDashboardCountsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class RefreshHardwareDashboardNavCountsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(
        private readonly string $fromIso,
        private readonly string $toIso,
        private readonly string $search = '',
    ) {}

    public function uniqueId(): string
    {
        return 'hardware-nav-counts|'.$this->fromIso.'|'.$this->toIso.'|'.strtoupper(trim($this->search));
    }

    public function handle(HardwareDashboardCountsCache $countsCache): void
    {
        $countsCache->warmLiveCounts(
            Carbon::parse($this->fromIso),
            Carbon::parse($this->toIso),
            $this->search,
        );
    }
}
