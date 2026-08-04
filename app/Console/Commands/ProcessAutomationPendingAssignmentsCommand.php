<?php

namespace App\Console\Commands;

use App\Services\Assignment\ReadyQueueAdminAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('service-cases:process-automation-pending')]
#[Description('Assign service cases whose automation-pending grace period has expired')]
class ProcessAutomationPendingAssignmentsCommand extends Command
{
    public function __construct(
        private readonly ServiceCaseAutomationGraceService $automationGraceService,
        private readonly ReadyQueueAdminAssignmentService $readyQueueAdminAssignmentService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $processed = $this->automationGraceService->processExpiredGracePeriods();

        Log::info('Automation pending grace period processing completed.', [
            'processed' => $processed,
        ]);

        $this->info(sprintf('Processed %d automation-pending service case(s).', $processed));

        $pickedUp = $this->readyQueueAdminAssignmentService->pickupUnassignedReadyQueueCases();

        Log::info('Ready Queue unassigned pickup completed.', [
            'assigned' => $pickedUp,
        ]);

        $this->info(sprintf('Picked up %d unassigned Ready Queue service case(s).', $pickedUp));

        return self::SUCCESS;
    }
}
