<?php

namespace App\Services\Bonvoice;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;

class BonvoiceWebhookOutboxWriter
{
    public const EVENT_TYPE = 'bonvoice.webhook.process';

    public const AGGREGATE_TYPE = 'bonvoice_webhook_log';

    public function writeProcessingJob(int $webhookLogId): void
    {
        OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($webhookLogId)],
            [
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $webhookLogId,
                'payload' => [
                    'webhook_log_id' => $webhookLogId,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }

    private function idempotencyKey(int $webhookLogId): string
    {
        return sprintf('bonvoice.webhook.process.%d', $webhookLogId);
    }
}
