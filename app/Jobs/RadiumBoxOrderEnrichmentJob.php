<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueMetricsService;
use App\Infrastructure\Queue\QueueRouting;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RadiumBoxOrderEnrichmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    /**
     * Cover one dispatch cycle (tries + backoff peaks at 1800s) so recovery/live
     * paths cannot pile duplicate HTTP lookups for the same order.
     */
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $orderId,
    ) {
        $this->onQueue(QueueRouting::critical());
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

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
