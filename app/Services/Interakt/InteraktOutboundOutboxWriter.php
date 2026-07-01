<?php

namespace App\Services\Interakt;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;

class InteraktOutboundOutboxWriter
{
    public const EVENT_TYPE = 'interakt.template.send';

    public const AGGREGATE_TYPE = 'whatsapp_template_dispatch';

    public function writeSendJob(int $dispatchId): OutboxEvent
    {
        return OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($dispatchId)],
            [
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $dispatchId,
                'payload' => [
                    'dispatch_id' => $dispatchId,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }

    private function idempotencyKey(int $dispatchId): string
    {
        return sprintf('interakt.template.send.%d', $dispatchId);
    }
}
