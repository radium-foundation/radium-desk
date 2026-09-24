<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Models\Order;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;

class ServiceStatutoryInvoiceMintOutboxWriter
{
    public const EVENT_TYPE = 'statutory.invoice.service_mint';

    public const AGGREGATE_TYPE = 'support_order';

    public function enqueue(
        Order $order,
        ServiceStatutoryInvoiceMintTrigger $trigger,
        ?int $actorId = null,
        ?Carbon $availableAt = null,
    ): OutboxEvent {
        $idempotencyKey = self::idempotencyKeyForOrder($order->id);
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
                    'aggregate_id' => $order->id,
                    'payload' => $this->payload($order, $trigger, $actorId),
                    'status' => OutboxEventStatus::Pending,
                    'attempts' => $existing->status === OutboxEventStatus::Failed ? 0 : $existing->attempts,
                    'available_at' => $availableAt ?? now(),
                    'last_error' => null,
                    'processed_at' => null,
                ]);

                return $existing->fresh() ?? $existing;
            }
        }

        return OutboxEvent::query()->create([
            'idempotency_key' => $idempotencyKey,
            'event_type' => self::EVENT_TYPE,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => $this->payload($order, $trigger, $actorId),
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => $availableAt ?? now(),
        ]);
    }

    public function recordPermanentFailure(
        Order $order,
        ServiceStatutoryInvoiceMintTrigger $trigger,
        string $error,
        ?int $actorId = null,
    ): OutboxEvent {
        $idempotencyKey = self::idempotencyKeyForOrder($order->id);
        $existing = OutboxEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $values = [
            'event_type' => self::EVENT_TYPE,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => $this->payload($order, $trigger, $actorId),
            'status' => OutboxEventStatus::Failed,
            'available_at' => now(),
            'last_error' => $error,
            'processed_at' => now(),
        ];

        if ($existing !== null) {
            $existing->update($values);

            return $existing->fresh() ?? $existing;
        }

        return OutboxEvent::query()->create([
            'idempotency_key' => $idempotencyKey,
            'attempts' => 1,
            ...$values,
        ]);
    }

    public static function idempotencyKeyForOrder(int $orderPk): string
    {
        return 'statutory-service-mint:order:'.$orderPk;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Order $order,
        ServiceStatutoryInvoiceMintTrigger $trigger,
        ?int $actorId,
    ): array {
        return [
            'order_pk' => $order->id,
            'order_id' => $order->order_id,
            'trigger' => $trigger->value,
            'actor_id' => $actorId,
        ];
    }
}
