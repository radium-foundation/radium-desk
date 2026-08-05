<?php

namespace App\Console\Commands;

use App\Services\Refunds\RefundCompletedOpenCaseRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('refunds:repair-completed-open-cases {--dry-run : Preview repairs without writing}')]
#[Description('Close open service cases stuck after refund completion (status=completed + active refund hold)')]
class RepairCompletedRefundOpenCasesCommand extends Command
{
    public function __construct(
        private readonly RefundCompletedOpenCaseRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $summary = $this->repairService->repair(dryRun: $dryRun);

        if ($summary['configuration_error'] !== null) {
            $this->error($summary['configuration_error']);

            return self::FAILURE;
        }

        $this->info(sprintf('scanned: %d', $summary['scanned']));
        $this->info(sprintf('%s: %d', $dryRun ? 'would_repair' : 'repaired', $summary['repaired']));
        $this->info(sprintf('failed: %d', $summary['failed']));

        if ($summary['samples'] !== []) {
            $this->newLine();
            $this->info('samples:');

            foreach ($summary['samples'] as $sample) {
                $this->line(json_encode($sample, JSON_UNESCAPED_SLASHES));
            }
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
