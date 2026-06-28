<?php

namespace App\Infrastructure\IntegrationHealth;

use Carbon\CarbonInterface;

readonly class CashfreeHealthDetails
{
    public function __construct(
        public ?CarbonInterface $lastWebhookAt,
        public ?CarbonInterface $lastSuccessfulWebhookAt,
        public int $failedWebhooks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'last_webhook_at' => $this->lastWebhookAt?->toIso8601String(),
            'last_successful_webhook_at' => $this->lastSuccessfulWebhookAt?->toIso8601String(),
            'failed_webhooks' => $this->failedWebhooks,
        ];
    }
}
