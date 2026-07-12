<?php

namespace App\Console\Commands;

use App\Services\Automation\CustomerWaitingLifecycleRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customer-waiting:repair-lifecycle {--dry-run : Preview repairs without writing} {--close-legacy : Also close legacy waiting cases with no waiting state}')]
#[Description('Repair stale waiting states, policy mismatches, and optionally legacy waiting cases (no customer notifications)')]
class CustomerWaitingLifecycleRepairCommand extends Command
{
    public function __construct(
        private readonly CustomerWaitingLifecycleRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $closeLegacy = (bool) $this->option('close-legacy');

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $this->warn('This command never sends customer notifications.');

        $summary = $this->repairService->repair(
            dryRun: $dryRun,
            closeLegacy: $closeLegacy,
        );

        if ($summary->configurationError !== null) {
            $this->error($summary->configurationError);

            return self::FAILURE;
        }

        foreach ($summary->counts as $key => $value) {
            $this->info(sprintf('%s: %d', $key, $value));
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
