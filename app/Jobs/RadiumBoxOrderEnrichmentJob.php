<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueMetricsService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RadiumBoxOrderEnrichmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(
        public readonly int $orderId,
    ) {}

    public function handle(
        RadiumBoxOrderEnrichmentService $enrichmentService,
        QueueMetricsService $queueMetricsService,
    ): void {
        $startedAt = microtime(true);

        $enrichmentService->process(
            orderId: $this->orderId,
            attempt: $this->attempts(),
        );

        $queueMetricsService->recordJobSuccess((microtime(true) - $startedAt) * 1000);
    }

    public function failed(?Throwable $exception): void
    {
        app(RadiumBoxOrderEnrichmentService::class)->markFailed(
            orderId: $this->orderId,
            errorMessage: $exception?->getMessage(),
        );

        Log::warning('RadiumBox order enrichment exhausted retries.', [
            'order_id' => $this->orderId,
            'attempt' => $this->attempts(),
            'duration_ms' => null,
            'result' => 'failed',
            'error_message' => $exception?->getMessage(),
        ]);
    }
}
