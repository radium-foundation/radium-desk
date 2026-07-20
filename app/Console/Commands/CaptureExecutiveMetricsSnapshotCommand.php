<?php

namespace App\Console\Commands;

use App\Services\Executive\Snapshots\ExecutiveSnapshotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('executive:snapshot')]
#[Description('Capture hourly executive metric snapshots for historical trends')]
class CaptureExecutiveMetricsSnapshotCommand extends Command
{
    public function __construct(
        private readonly ExecutiveSnapshotService $snapshotService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->snapshotService->capture();
            $count = $result['written'];

            $this->info("Executive metrics snapshot captured ({$count} metrics).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Failed to capture executive metrics snapshot: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
