<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\DeskAuthoritativePaymentEvent;
use App\Models\Order;
use Illuminate\Support\Carbon;
use RuntimeException;

final class DeskAuthoritativePaymentEventFactory
{
    public function fromDeskOrder(Order $order, string $verificationSource = 'cashfree_webhook'): DeskAuthoritativePaymentEvent
    {
        if (! $order->isCashfreeVerified()) {
            throw new RuntimeException('Cannot build authoritative payment event for unverified order.');
        }

        $businessOrderId = strtoupper(trim((string) $order->order_id));
        $paymentId = trim((string) $order->cashfree_payment_id);
        $amount = $order->payment_amount;

        if ($amount === null || (float) $amount <= 0) {
            throw new RuntimeException('Authoritative payment event requires a positive payment_amount on the Desk order.');
        }

        $idempotencyKey = sprintf(
            'desk-payment:radiumbox_com:%s:%s',
            $businessOrderId,
            $paymentId,
        );

        $eventId = sprintf('desk-payment:order:%d:%s', (int) $order->id, $paymentId);

        $verifiedAt = $order->payment_date instanceof Carbon
            ? $order->payment_date->copy()
            : ($order->updated_at ?? now());

        return new DeskAuthoritativePaymentEvent(
            eventId: $eventId,
            idempotencyKey: $idempotencyKey,
            businessOrderId: $businessOrderId,
            deskOrderId: (int) $order->id,
            provider: 'cashfree',
            paymentStatus: 'paid',
            currency: 'INR',
            amount: number_format((float) $amount, 2, '.', ''),
            cashfreePaymentId: $paymentId,
            cashfreeOrderId: filled($order->gateway_order_id) ? (string) $order->gateway_order_id : null,
            bankReference: filled($order->bank_reference) ? (string) $order->bank_reference : null,
            verifiedAt: $verifiedAt,
            verificationSource: $verificationSource,
        );
    }
}