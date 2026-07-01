<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationSchedulerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:run {--chunk=100 : Number of waiting states to process per chunk}')]
#[Description('Evaluate active waiting states and execute due automation actions')]
class RunAutomationCommand extends Command
{
    public function handle(AutomationSchedulerService $automationSchedulerService): int
    {
        $chunkOption = $this->option('chunk');
        $chunkSize = is_string($chunkOption) && $chunkOption !== ''
            ? max(1, (int) $chunkOption)
            : 100;

        $result = $automationSchedulerService->run(chunkSize: $chunkSize);

        if (! $result->enabled) {
            $this->info('Automation scheduler is disabled.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scanned %d waiting state(s); found %d due action(s); executed %d; skipped %d; failures %d.',
            $result->waitingStatesScanned,
            $result->dueActionsFound,
            $result->executed,
            $result->skipped,
            $result->failures,
        ));

        return self::SUCCESS;
    }
}
