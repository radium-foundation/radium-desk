<?php

namespace App\Services\Refunds;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use App\Models\RefundStatutoryAdjustment;
use Illuminate\Support\Carbon;

final class RefundStatutoryAdjustmentOutboxWriter
{
    public const EVENT_TYPE = 'refund.statutory_adjustment';

    public const AGGREGATE_TYPE = 'refund_request';

    public function enqueue(RefundStatutoryAdjustment $adjustment, ?Carbon $availableAt = null): OutboxEvent
    {
        $idempotencyKey = self::idempotencyKeyForRefund($adjustment->refund_request_id);
        $existing = OutboxEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->status === OutboxEventStatus::Completed) {
                return $existing;
            }

            if ($existing->status === OutboxEventStatus::Failed
                || $existing->status === OutboxEventStatus::Pending) {
                $existing->update([
                    'event_type' => self::EVENT_TYPE,
                    'aggregate_type' => self::AGGREGATE_TYPE,
                    'aggregate_id' => $adjustment->refund_request_id,
                    'payload' => $this->payload($adjustment),
                    'status' => OutboxEventStatus::Pending,
                    'attempts' => $existing->status === OutboxEventStatus::Failed ? 0 : $existing->attempts,
                    'available_at' => $availableAt ?? now(),
                    'last_error' => null,
                    'processed_at' => null,
                ]);

                return $existing->fresh() ?? $existing;
            }
        }

        $event = OutboxEvent::query()->create([
            'idempotency_key' => $idempotencyKey,
            'event_type' => self::EVENT_TYPE,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $adjustment->refund_request_id,
            'payload' => $this->payload($adjustment),
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => $availableAt ?? now(),
        ]);

        $adjustment->update(['outbox_event_id' => $event->id]);

        return $event;
    }

    public static function idempotencyKeyForRefund(int $refundRequestId): string
    {
        return 'refund-statutory-adjustment:refund:'.$refundRequestId;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RefundStatutoryAdjustment $adjustment): array
    {
        return [
            'refund_request_id' => $adjustment->refund_request_id,
            'refund_statutory_adjustment_id' => $adjustment->id,
            'statutory_invoice_id' => $adjustment->statutory_invoice_id,
            'idempotency_key' => $adjustment->idempotency_key,
        ];
    }
}
