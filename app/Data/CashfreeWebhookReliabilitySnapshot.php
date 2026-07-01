<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class CashfreeWebhookReliabilitySnapshot
{
    public function __construct(
        public int $ordersCreated,
        public int $deferredTaskFailures,
        public int $successfulRetries,
        public ?Carbon $lastOrderCreatedAt,
        public ?Carbon $lastDeferredFailureAt,
        public ?Carbon $lastSuccessfulRetryAt,
        public Carbon $capturedAt,
    ) {}

    /**
     * @return array<string, int>
     */
    public function dashboardCounts(): array
    {
        return [
            'cashfree_orders_created' => $this->ordersCreated,
            'cashfree_deferred_failures' => $this->deferredTaskFailures,
            'cashfree_deferred_retries' => $this->successfulRetries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'orders_created' => $this->ordersCreated,
            'deferred_task_failures' => $this->deferredTaskFailures,
            'successful_retries' => $this->successfulRetries,
            'last_order_created_at' => $this->lastOrderCreatedAt?->toIso8601String(),
            'last_deferred_failure_at' => $this->lastDeferredFailureAt?->toIso8601String(),
            'last_successful_retry_at' => $this->lastSuccessfulRetryAt?->toIso8601String(),
            'captured_at' => $this->capturedAt->toIso8601String(),
        ];
    }
}
