<?php

namespace App\Infrastructure\IntegrationHealth;

use Carbon\CarbonInterface;

readonly class CashfreeHealthDetails
{
    public function __construct(
        public ?CarbonInterface $lastWebhookAt,
        public ?CarbonInterface $lastSuccessfulWebhookAt,
        public int $failedWebhooks,
        public int $activeFailedWebhooks,
        public int $historicalResolvedFailures,
        public int $paidWithoutDeskOrderCount,
    ) {}

    public function isHealthy(): bool
    {
        return $this->paidWithoutDeskOrderCount === 0 && $this->activeFailedWebhooks === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'last_webhook_at' => $this->lastWebhookAt?->toIso8601String(),
            'last_successful_webhook_at' => $this->lastSuccessfulWebhookAt?->toIso8601String(),
            'failed_webhooks' => $this->failedWebhooks,
            'active_failed_webhooks' => $this->activeFailedWebhooks,
            'historical_resolved_failures' => $this->historicalResolvedFailures,
            'paid_without_desk_order_count' => $this->paidWithoutDeskOrderCount,
            'is_healthy' => $this->isHealthy(),
        ];
    }
}
