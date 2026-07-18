<?php

namespace App\Console\Commands;

use App\Services\Operations\DeferredSmartAssignmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('service-cases:process-deferred-smart-assignment')]
#[Description('Assign support-appointment cases pending deferred smart assignment')]
class ProcessDeferredSmartAssignmentsCommand extends Command
{
    public function __construct(
        private readonly DeferredSmartAssignmentService $deferredSmartAssignmentService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $processed = $this->deferredSmartAssignmentService->processPendingBatch();

        Log::info('Deferred smart assignment processing completed.', [
            'assigned' => $processed,
        ]);

        $this->info(sprintf('Assigned %d deferred smart-assignment service case(s).', $processed));

        return self::SUCCESS;
    }
}
