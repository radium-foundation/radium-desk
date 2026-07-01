<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class CashfreeWebhookReliabilitySnapshot
{
    public function __construct(
        public int $ordersCreated,
        public int $outboxPending,
        public int $outboxFailed,
        public int $outboxCompletedToday,
        public int $outboxRetryCount,
        public ?Carbon $lastOrderCreatedAt,
        public Carbon $capturedAt,
    ) {}

    /**
     * @return array<string, int>
     */
    public function dashboardCounts(): array
    {
        return [
            'cashfree_orders_created' => $this->ordersCreated,
            'cashfree_outbox_pending' => $this->outboxPending,
            'cashfree_outbox_failed' => $this->outboxFailed,
            'cashfree_outbox_completed_today' => $this->outboxCompletedToday,
            'cashfree_outbox_retries' => $this->outboxRetryCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'orders_created' => $this->ordersCreated,
            'outbox_pending' => $this->outboxPending,
            'outbox_failed' => $this->outboxFailed,
            'outbox_completed_today' => $this->outboxCompletedToday,
            'outbox_retry_count' => $this->outboxRetryCount,
            'last_order_created_at' => $this->lastOrderCreatedAt?->toIso8601String(),
            'captured_at' => $this->capturedAt->toIso8601String(),
        ];
    }
}
