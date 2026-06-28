<?php

namespace App\Console\Commands;

use App\Infrastructure\IntegrationHealth\IntegrationHealthRegistry;
use App\Infrastructure\Queue\QueueMetricsService;
use Illuminate\Console\Command;

class CollectInfrastructureMetricsCommand extends Command
{
    protected $signature = 'infrastructure:metrics:collect';

    protected $description = 'Capture queue and integration health metrics for monitoring';

    public function handle(
        QueueMetricsService $queueMetricsService,
        IntegrationHealthRegistry $integrationHealthRegistry,
    ): int {
        $queueSnapshot = $queueMetricsService->capture();
        $integrationPayload = $integrationHealthRegistry->captureAndCache();

        $this->info('Queue metrics captured.');
        $this->line(json_encode($queueSnapshot->toArray(), JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('Integration health captured.');
        $this->line(json_encode($integrationPayload, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
