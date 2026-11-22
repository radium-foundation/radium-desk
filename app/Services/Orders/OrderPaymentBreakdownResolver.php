<?php

namespace App\Services\Orders;

use App\Data\Orders\OrderPaymentBreakdown;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Services\RefundCalculationService;
use Illuminate\Validation\ValidationException;

final class OrderPaymentBreakdownResolver
{
    public function __construct(
        private readonly RefundCalculationService $refundCalculations,
    ) {}

    public function resolve(Order $order, ?CommerceOrder $commerce = null): OrderPaymentBreakdown
    {
        $commerce ??= $this->resolveCommerceOrder($order);

        if ($commerce === null) {
            throw ValidationException::withMessages([
                'commerce_order' => 'Commerce order correlation is required for payment breakdown.',
            ]);
        }

        $orderTotal = round((float) ($commerce->order_value ?? 0), 2);
        if ($orderTotal < 0.01) {
            throw ValidationException::withMessages([
                'order_total' => 'Commerce order value is missing or zero.',
            ]);
        }

        $walletTender = round(max(0, (float) ($commerce->wallet_tender_amount ?? 0)), 2);
        $externalTender = round(max(0, $orderTotal - $walletTender), 2);
        if ($externalTender < 0.01 && $order->isCashfreeVerified()) {
            $deskCashfree = round((float) ($order->payment_amount ?? 0), 2);
            if ($deskCashfree >= 0.01) {
                $externalTender = $deskCashfree;
            }
        }

        $alreadyRefunded = $this->refundCalculations->alreadyRefundedAmount($order);
        $maximumRefundable = max(0, round($orderTotal - $alreadyRefunded, 2));
        $splitTender = $walletTender >= 0.01 && $externalTender >= 0.01;

        return new OrderPaymentBreakdown(
            orderTotal: $orderTotal,
            walletTender: $walletTender,
            externalTender: $externalTender,
            alreadyRefunded: $alreadyRefunded,
            maximumRefundable: $maximumRefundable,
            splitTender: $splitTender,
        );
    }

    private function resolveCommerceOrder(Order $order): ?CommerceOrder
    {
        return CommerceOrder::query()
            ->where('support_order_id', $order->id)
            ->orWhereRaw('UPPER(source_id) = ?', [strtoupper((string) $order->order_id)])
            ->orderByDesc('id')
            ->first();
    }
}
