<?php

namespace App\Console\Commands;

use App\Services\AutomationOperationsSnapshotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:snapshot {--reconcile : Force a full rebuild (scheduled safety net)}')]
#[Description('Refresh the cached automation operations dashboard snapshot (event-driven incremental; --reconcile for full rebuild)')]
class AutomationSnapshotCommand extends Command
{
    public function __construct(
        private readonly AutomationOperationsSnapshotService $snapshotService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reconcile = (bool) $this->option('reconcile');
        $started = hrtime(true);
        $result = $this->snapshotService->refreshDetailed(forceReconcile: $reconcile);
        $elapsedMs = round((hrtime(true) - $started) / 1e6, 1);
        $snapshot = $result['snapshot'];
        $mode = $result['mode'];

        $this->info(match ($mode) {
            'reconcile' => 'Automation operations snapshot reconciled (full rebuild).',
            'full-rebuild' => 'Automation operations snapshot rebuilt (dirty slices required full pass).',
            default => 'Automation operations snapshot updated incrementally.',
        });
        $this->line('Mode: '.$mode);
        $this->line('Elapsed: '.$elapsedMs.'ms');
        if ($result['dirty_slices'] !== []) {
            $this->line('Dirty slices: '.implode(', ', $result['dirty_slices']));
        }
        $this->line('Automation pending: '.($snapshot->healthCounts['automation_pending'] ?? 0));
        $this->line('Validation failures tracked: '.array_sum($snapshot->validationByCategory));

        return self::SUCCESS;
    }
}
