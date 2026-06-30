<?php

namespace App\Console\Commands;

use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationHealthService;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:repair {--dry-run : Show repair candidates without re-evaluating assignment eligibility}')]
#[Description('Re-evaluate automation-pending and exceptional service cases')]
class AutomationRepairCommand extends Command
{
    public function __construct(
        private readonly ServiceCaseAutomationHealthService $healthService,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly ServiceCaseAutomationMonitorService $monitorService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $orders = $this->healthService->ordersNeedingRepair();

        if ($orders->isEmpty()) {
            $this->info('No automation repair candidates found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d order(s) would be re-evaluated.', $orders->count()));

            foreach ($orders as $order) {
                $this->line('- '.$order->order_id);
            }

            return self::SUCCESS;
        }

        $processed = 0;

        foreach ($orders as $order) {
            $order->loadMissing('incidents.creator');
            $actor = $order->incidents->first()?->creator
                ?? $this->monitorService->resolveAutomationActor();

            $this->eligibilityService->evaluateAssignmentEligibility($order->fresh(), $actor);
            $processed++;
        }

        $this->info(sprintf('Re-evaluated assignment eligibility for %d order(s).', $processed));

        return self::SUCCESS;
    }
}
