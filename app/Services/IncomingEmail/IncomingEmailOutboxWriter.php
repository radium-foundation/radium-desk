<?php

namespace App\Services\IncomingEmail;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;

class IncomingEmailOutboxWriter
{
    public const EVENT_TYPE = 'email.inbound.process';

    public const AGGREGATE_TYPE = 'incoming_email_message';

    public function writeProcessingJob(int $messageId): void
    {
        OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($messageId)],
            [
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $messageId,
                'payload' => [
                    'incoming_email_message_id' => $messageId,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }

    private function idempotencyKey(int $messageId): string
    {
        return sprintf('email.inbound.process.%d', $messageId);
    }
}
