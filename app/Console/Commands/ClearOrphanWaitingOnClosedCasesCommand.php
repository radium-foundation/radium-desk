<?php

namespace App\Console\Commands;

use App\Services\Automation\CustomerWaitingLifecycleRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customer-waiting:clear-orphans-on-closed {--dry-run : Preview orphans without clearing}')]
#[Description('Clear active customer-waiting states attached to closed service cases (no customer notifications)')]
class ClearOrphanWaitingOnClosedCasesCommand extends Command
{
    public function __construct(
        private readonly CustomerWaitingLifecycleRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $this->warn('This command never sends customer notifications.');

        $summary = $this->repairService->repairOrphansOnClosed(dryRun: $dryRun);

        if ($summary->configurationError !== null) {
            $this->error($summary->configurationError);

            return self::FAILURE;
        }

        $found = (int) ($summary->counts['stale_on_closed_found'] ?? 0);
        $repaired = (int) ($summary->counts['stale_on_closed_cleared'] ?? 0);

        $this->info(sprintf('Total found: %d', $found));
        $this->info(sprintf('Total repaired: %d', $dryRun ? 0 : $repaired));

        if ($dryRun) {
            $this->info(sprintf('Would repair: %d', $found));
        }

        if ($summary->samples !== []) {
            $this->newLine();
            $this->info('Samples:');

            foreach ($summary->samples as $sample) {
                $this->line(json_encode($sample, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
