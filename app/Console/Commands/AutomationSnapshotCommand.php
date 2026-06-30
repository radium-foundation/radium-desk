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
        $snapshot = $this->snapshotService->refresh();

        $this->info('Automation operations snapshot refreshed.');
        $this->line('Automation pending: '.($snapshot->healthCounts['automation_pending'] ?? 0));
        $this->line('Validation failures tracked: '.array_sum($snapshot->validationByCategory));

        return self::SUCCESS;
    }
}
