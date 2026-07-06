<?php

namespace App\Console\Commands;

use App\Services\Automation\CustomerWaitingLegacyCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('customer-waiting:cleanup-legacy {--dry-run : Show legacy waiting cases without closing them}')]
#[Description('Close legacy customer waiting cases created before the lifecycle upgrade')]
class CustomerWaitingLegacyCleanupCommand extends Command
{
    public function __construct(
        private readonly CustomerWaitingLegacyCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $summary = $this->cleanupService->cleanup($dryRun);

        $this->info(sprintf('Total found: %d', $summary->totalFound));

        if ($dryRun) {
            $this->info(sprintf('Would close: %d', $summary->wouldClose));
        }

        $this->info(sprintf('Cases closed: %d', $summary->casesClosed));
        $this->info(sprintf('Skipped: %d', $summary->skipped));

        if ($summary->skipReasons !== []) {
            $this->newLine();
            $this->info('Skipped:');

            foreach ($summary->skipReasons as $reason => $count) {
                $this->info(sprintf('- %s: %d', $reason, $count));
            }
        }

        Log::info('Customer waiting legacy cleanup command completed.', [
            'dry_run' => $dryRun,
            'total_found' => $summary->totalFound,
            'would_close' => $summary->wouldClose,
            'cases_closed' => $summary->casesClosed,
            'skipped' => $summary->skipped,
            'skip_reasons' => $summary->skipReasons,
        ]);

        return self::SUCCESS;
    }
}
