<?php

namespace App\Services\RadiumBox;

use App\Enums\OutboxEventStatus;
use App\Models\Order;
use App\Models\OutboxEvent;

/**
 * Durable Desk → RadiumBox payment confirmation delivery (confirm-payment).
 */
final class RadiumBoxPaymentConfirmationOutboxWriter
{
    public const EVENT_TYPE = 'radiumbox.payment_confirm';

    public const AGGREGATE_TYPE = 'order';

    public function enqueueIfRequired(Order $order): ?OutboxEvent
    {
        if (! app(RadiumBoxPaymentConfirmationService::class)->requiresBoxPaymentConfirmation($order)) {
            return null;
        }

        if (! config('radiumbox.payment_confirm.outbox_enabled', true)) {
            return null;
        }

        $idempotencyKey = sprintf('radiumbox.payment_confirm:%d', $order->id);

        return OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $order->id,
                'payload' => [
                    'order_db_id' => $order->id,
                    'business_order_id' => $order->order_id,
                    'cashfree_payment_id' => $order->cashfree_payment_id,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }
}