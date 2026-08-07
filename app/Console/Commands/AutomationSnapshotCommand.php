<?php

namespace App\Console\Commands;

use App\Services\AutomationOperationsSnapshotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:snapshot')]
#[Description('Regenerate the cached automation operations dashboard snapshot')]
class AutomationSnapshotCommand extends Command
{
    public function __construct(
        private readonly AutomationOperationsSnapshotService $snapshotService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $started = hrtime(true);
        $result = $this->snapshotService->refreshDetailed();
        $elapsedMs = round((hrtime(true) - $started) / 1e6, 1);
        $snapshot = $result['snapshot'];

        $this->info($result['rebuilt']
            ? 'Automation operations snapshot refreshed.'
            : 'Automation operations snapshot reused (content unchanged; time fields updated).');
        $this->line('Mode: '.($result['rebuilt'] ? 'full-rebuild' : 'incremental'));
        $this->line('Elapsed: '.$elapsedMs.'ms');
        $this->line('Automation pending: '.($snapshot->healthCounts['automation_pending'] ?? 0));
        $this->line('Validation failures tracked: '.array_sum($snapshot->validationByCategory));

        return self::SUCCESS;
    }
}
