<?php

namespace App\Console\Commands;

use App\Services\Operations\IraMemoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CaptureIraMemorySnapshotCommand extends Command
{
    protected $signature = 'ira:capture-memory-snapshot {--date= : Snapshot date (Y-m-d)}';

    protected $description = 'Capture Ira operational memory snapshot for daily comparison';

    public function handle(IraMemoryService $memoryService): int
    {
        $dateOption = $this->option('date');
        $at = $dateOption !== null && $dateOption !== ''
            ? Carbon::parse((string) $dateOption)->startOfDay()
            : now();

        $snapshot = $memoryService->capture($at);
        $pruned = $memoryService->pruneOldSnapshots($at);

        $this->info(sprintf(
            'Captured Ira memory snapshot for %s (pruned %d old snapshot(s)).',
            $snapshot->snapshot_date->toDateString(),
            $pruned,
        ));

        return self::SUCCESS;
    }
}
