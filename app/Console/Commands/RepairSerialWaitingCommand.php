<?php

namespace App\Console\Commands;

use App\Services\Automation\SerialWaitingRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('incidents:repair-serial-waiting {--dry-run : Preview repairs without writing}')]
#[Description('Clear stuck serial_number waiting states when order validation already passes, then re-evaluate Ready Queue assignment')]
class RepairSerialWaitingCommand extends Command
{
    public function __construct(
        private readonly SerialWaitingRepairService $repairService,
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

        if ($summary->configurationError !== null) {
            $this->error($summary->configurationError);

            return self::FAILURE;
        }

        $this->info(sprintf('scanned: %d', $summary->scanned));
        $this->info(sprintf('repaired: %d', $summary->repaired));
        $this->info(sprintf('skipped: %d', $summary->skipped));

        if ($summary->samples !== []) {
            $this->newLine();
            $this->info('samples:');

            foreach ($summary->samples as $sample) {
                $this->line(json_encode($sample, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
