<?php

namespace App\Jobs;

use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCashfreeWebhookDeferredOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $operation,
        public readonly int $orderId,
        public readonly int $incidentId,
        public readonly int $actorId,
    ) {}

    public function handle(
        CashfreeWebhookDeferredOperationsService $deferredOperationsService,
        CashfreeWebhookReliabilityMetrics $reliabilityMetrics,
    ): void {
        $deferredOperationsService->retryOperation(
            operation: $this->operation,
            orderId: $this->orderId,
            incidentId: $this->incidentId,
            actorId: $this->actorId,
        );

        $reliabilityMetrics->recordSuccessfulRetry($this->operation);

        Log::info('[Cashfree Webhook] Deferred operation retry succeeded.', [
            'operation' => $this->operation,
            'order_id' => $this->orderId,
            'incident_id' => $this->incidentId,
            'actor_id' => $this->actorId,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('[Cashfree Webhook] Deferred operation retry exhausted.', [
            'operation' => $this->operation,
            'order_id' => $this->orderId,
            'incident_id' => $this->incidentId,
            'actor_id' => $this->actorId,
            'exception' => $exception !== null ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
