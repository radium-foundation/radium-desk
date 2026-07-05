<?php

namespace App\Data;

use App\Enums\CashfreeWebhookFailureCategory;
use Illuminate\Support\Carbon;

readonly class CashfreeFailedWebhookRecord
{
    public function __construct(
        public int $webhookLogId,
        public CashfreeWebhookFailureCategory $category,
        public string $reason,
        public ?string $orderId,
        public ?string $cfPaymentId,
        public Carbon $failedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'webhook_log_id' => $this->webhookLogId,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'reason' => $this->reason,
            'order_id' => $this->orderId,
            'cf_payment_id' => $this->cfPaymentId,
            'failed_at' => $this->failedAt->toIso8601String(),
        ];
    }
}
