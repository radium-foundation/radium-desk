<?php

namespace App\Services\Refunds;

use App\Models\HardwareFulfilment;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Blocks direct {@see RefundRequestService::create()} for hardware product orders unless an
 * authorized workflow supplies an internal bypass token (pre-shipment Finance, return-case online submit, etc.).
 */
final class HardwareOrderRefundCaseGate
{
    public const BYPASS_RETURN_EXCHANGE_CASE_ONLINE = 'return_exchange_case_online';

    public const BYPASS_PRE_SHIPMENT_CANCELLATION = 'pre_shipment_cancellation';

    public const BYPASS_PRE_SHIPMENT_ALLOCATION = 'pre_shipment_allocation';

    public const BYPASS_PRE_SHIPMENT_WALLET_CREDIT = 'pre_shipment_wallet_credit';

    /** @var list<string> */
    private const ALLOWED_BYPASSES = [
        self::BYPASS_RETURN_EXCHANGE_CASE_ONLINE,
        self::BYPASS_PRE_SHIPMENT_CANCELLATION,
        self::BYPASS_PRE_SHIPMENT_ALLOCATION,
        self::BYPASS_PRE_SHIPMENT_WALLET_CREDIT,
    ];

    public function isHardwareRefundOrder(Order $order): bool
    {
        if (Order::isHardwareOrderId($order->order_id)) {
            return true;
        }

        return HardwareFulfilment::query()
            ->where('support_order_id', $order->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertMayCreateRefund(Order $order, array $data): void
    {
        if (! $this->isHardwareRefundOrder($order)) {
            return;
        }

        $bypass = isset($data['hardware_refund_gate_bypass'])
            ? (string) $data['hardware_refund_gate_bypass']
            : '';

        if ($bypass !== '' && in_array($bypass, self::ALLOWED_BYPASSES, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'order_id' => 'Hardware product refunds cannot be created from the general refund form. '
                .'Use agent pre-shipment cancellation, a return/exchange case (receive → inspect → case refund submit), '
                .'or Finance-approved pre-shipment allocation. Agent request and Finance approval remain separate from execution.',
        ]);
    }
}
