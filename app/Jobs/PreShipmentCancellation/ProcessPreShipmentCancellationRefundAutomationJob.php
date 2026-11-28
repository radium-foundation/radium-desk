<?php

namespace App\Jobs\PreShipmentCancellation;

use App\Infrastructure\Queue\QueueRouting;
use App\Services\PreShipmentCancellation\PreShipmentCancellationRefundAutomationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-commit PSC refund link + optional desk-first wallet execution.
 */
class ProcessPreShipmentCancellationRefundAutomationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const IDENTITY = 'preship-cancel-refund-automation';

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $preShipmentCancellationRequestId,
        public readonly int $actorUserId,
    ) {
        $this->onQueue(QueueRouting::critical());
    }

    public function uniqueId(): string
    {
        return 'psc-refund:'.$this->preShipmentCancellationRequestId;
    }

    public function handle(PreShipmentCancellationRefundAutomationService $automation): void
    {
        try {
            $automation->process($this->preShipmentCancellationRequestId, $this->actorUserId);
        } catch (Throwable $exception) {
            Log::error('[preship-cancel-refund-automation] Job attempt failed; cancellation remains committed.', [
                'pre_shipment_cancellation_request_id' => $this->preShipmentCancellationRequestId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
