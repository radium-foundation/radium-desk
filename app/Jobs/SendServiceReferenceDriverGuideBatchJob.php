<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueRouting;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\ReferenceNumberCommunicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes driver-installation guides for a batch Assign Reference in assignment order.
 *
 * Backward compatible with per-order {@see SendServiceReferenceDriverGuideJob}:
 * same communication service, idempotency audits, and notifications queue.
 */
class SendServiceReferenceDriverGuideBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /**
     * Large batches can exceed the default worker timeout (Interakt/email per order).
     */
    public int $timeout = 900;

    /**
     * @param  list<array{order_id: int, service_reference: string}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $actorId,
    ) {
        $this->onQueue(QueueRouting::notifications());
    }

    public function handle(ReferenceNumberCommunicationService $referenceNumberCommunicationService): void
    {
        if ($this->items === []) {
            return;
        }

        $actor = User::query()->find($this->actorId);

        if ($actor === null) {
            Log::warning('service_reference.driver_guide.batch.skipped', [
                'reason' => 'actor_not_found',
                'actor_id' => $this->actorId,
                'order_count' => count($this->items),
            ]);

            return;
        }

        foreach ($this->items as $item) {
            $orderId = (int) ($item['order_id'] ?? 0);
            $serviceReference = trim((string) ($item['service_reference'] ?? ''));

            if ($orderId <= 0 || $serviceReference === '') {
                continue;
            }

            $order = Order::query()->find($orderId);

            if ($order === null) {
                Log::warning('service_reference.driver_guide.batch.order_skipped', [
                    'reason' => 'order_not_found',
                    'order_id' => $orderId,
                ]);

                continue;
            }

            $referenceNumberCommunicationService->handleServiceReferenceAssigned(
                order: $order,
                serviceReference: $serviceReference,
                actor: $actor,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('service_reference.driver_guide.batch.failed', [
            'order_count' => count($this->items),
            'actor_id' => $this->actorId,
            'attempt' => $this->attempts(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
