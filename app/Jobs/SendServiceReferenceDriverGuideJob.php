<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\ReferenceNumberCommunicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendServiceReferenceDriverGuideJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $orderId,
        public readonly string $serviceReference,
        public readonly int $actorId,
    ) {}

    public function handle(ReferenceNumberCommunicationService $referenceNumberCommunicationService): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            Log::warning('service_reference.driver_guide.job.skipped', [
                'reason' => 'order_not_found',
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $actor = User::query()->find($this->actorId);

        if ($actor === null) {
            Log::warning('service_reference.driver_guide.job.skipped', [
                'reason' => 'actor_not_found',
                'order_id' => $this->orderId,
                'actor_id' => $this->actorId,
            ]);

            return;
        }

        $referenceNumberCommunicationService->handleServiceReferenceAssigned(
            order: $order,
            serviceReference: $this->serviceReference,
            actor: $actor,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('service_reference.driver_guide.job.failed', [
            'order_id' => $this->orderId,
            'service_reference' => $this->serviceReference,
            'actor_id' => $this->actorId,
            'attempt' => $this->attempts(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
