<?php

namespace App\Console\Commands;

use App\Services\Platform\Warmers\PlatformSnapshotWarmingService;
use Illuminate\Console\Command;

class WarmPlatformSnapshotsCommand extends Command
{
    protected $signature = 'platform:snapshots:warm';

    protected $description = 'Warm Priority-1 Platform zone snapshots and overall health caches';

    public function handle(PlatformSnapshotWarmingService $warming): int
    {
        $result = $warming->warmAll();

        $this->info(sprintf(
            'Warmed %d; skipped %d (fresh); failed %d; actor=%s',
            count($result['warmed']),
            count($result['skipped'] ?? []),
            count($result['failed']),
            $result['actor_id'] ?? 'none',
        ));

        if ($result['warmed'] !== []) {
            $this->line('Warmed: '.implode(', ', $result['warmed']));
        }

        if (($result['skipped'] ?? []) !== []) {
            $this->line('Skipped: '.implode(', ', $result['skipped']));
        }

        if ($result['failed'] !== []) {
            $this->warn('Failed: '.implode(', ', $result['failed']));
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
