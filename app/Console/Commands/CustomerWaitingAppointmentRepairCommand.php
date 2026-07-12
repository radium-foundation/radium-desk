<?php

namespace App\Console\Commands;

use App\Services\Automation\CustomerWaitingAppointmentRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customer-waiting:repair-appointments {--dry-run : Preview repairs without writing}')]
#[Description('Clear active waiting states where a scheduled support appointment already exists (one-time pre-fix repair)')]
class CustomerWaitingAppointmentRepairCommand extends Command
{
    public function __construct(
        private readonly CustomerWaitingAppointmentRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $this->warn('This command never closes incidents or notifies customers.');

        $summary = $this->repairService->repair(dryRun: $dryRun);

        if ($summary->configurationError !== null) {
            $this->error($summary->configurationError);

            return self::FAILURE;
        }

        $this->info(sprintf('appointments_found: %d', $summary->appointmentsFound));
        $this->info(sprintf('waiting_states_cleared: %d', $summary->waitingStatesCleared));
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
