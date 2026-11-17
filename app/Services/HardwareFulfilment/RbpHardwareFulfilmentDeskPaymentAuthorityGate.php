<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CashfreeWebhookLog;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use Illuminate\Validation\ValidationException;

/**
 * Desk-sovereign Cashfree proof for RBP gap recovery. Commerce payment_status alone is insufficient.
 */
final class RbpHardwareFulfilmentDeskPaymentAuthorityGate
{
    private const AMOUNT_TOLERANCE_INR = 0.02;

    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {}

    public function assertDeskPaymentAuthority(Order $deskOrder, CommerceOrder $commerceOrder): void
    {
        $sourceId = strtoupper(trim((string) $deskOrder->order_id));
        $commerceSource = strtoupper(trim((string) $commerceOrder->source_id));

        if ($sourceId !== $commerceSource) {
            throw ValidationException::withMessages([
                'payment' => 'Desk order id does not match commerce source_id.',
            ]);
        }

        if (! $deskOrder->isCashfreeVerified()) {
            throw ValidationException::withMessages([
                'payment' => 'Desk Cashfree verification (cashfree_payment_id) is required.',
            ]);
        }

        $cfPaymentId = trim((string) $deskOrder->cashfree_payment_id);

        $currency = strtoupper(trim((string) $commerceOrder->currency));
        if ($currency !== 'INR') {
            throw ValidationException::withMessages([
                'payment' => 'Commerce currency must be INR for gap recovery.',
            ]);
        }

        $commerceAmount = round((float) ($commerceOrder->order_value ?? 0), 2);
        if ($commerceAmount < 0.01) {
            throw ValidationException::withMessages([
                'payment' => 'Commerce order_value is missing or zero.',
            ]);
        }

        $deskAmount = round((float) ($deskOrder->payment_amount ?? 0), 2);
        $webhookAmount = $this->successfulWebhookPaymentAmount($cfPaymentId);
        $authoritativeAmount = $deskAmount >= 0.01 ? $deskAmount : ($webhookAmount ?? 0.0);

        if ($authoritativeAmount >= 0.01 && abs($authoritativeAmount - $commerceAmount) > self::AMOUNT_TOLERANCE_INR) {
            throw ValidationException::withMessages([
                'payment' => 'Desk or Cashfree webhook payment amount does not match commerce order_value.',
            ]);
        }

        if (! $this->hasSuccessfulCashfreeWebhookEvidence($cfPaymentId)) {
            throw ValidationException::withMessages([
                'payment' => 'No successful Cashfree webhook evidence matches this Desk payment id.',
            ]);
        }
    }

    /**
     * Reload Desk order under lock immediately before a recovery write.
     */
    public function lockedDeskOrder(string $sourceId): Order
    {
        $order = Order::query()
            ->where('order_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => 'Desk support order is missing.',
            ]);
        }

        return $order;
    }

    /**
     * Reload commerce under lock immediately before a recovery write.
     */
    public function lockedCommerceOrder(int $commerceOrderId): CommerceOrder
    {
        $order = CommerceOrder::query()
            ->with('items')
            ->whereKey($commerceOrderId)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'commerce' => 'Commerce order is missing.',
            ]);
        }

        return $order;
    }

    private function successfulWebhookPaymentAmount(string $cfPaymentId): ?float
    {
        if ($cfPaymentId === '') {
            return null;
        }

        foreach (CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->orderByDesc('id')
            ->limit(20)
            ->get() as $log) {
            $payload = $log->request_payload;
            if (! is_array($payload) || ! $this->payloadParser->isSuccessfulPayment($payload)) {
                continue;
            }

            $raw = $this->payloadParser->paymentAmount($payload);
            if ($raw === null || $raw === '') {
                continue;
            }

            return round((float) $raw, 2);
        }

        return null;
    }

    private function hasSuccessfulCashfreeWebhookEvidence(string $cfPaymentId): bool
    {
        if ($cfPaymentId === '') {
            return false;
        }

        return CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->contains(function (CashfreeWebhookLog $log): bool {
                $payload = $log->request_payload;
                if (! is_array($payload)) {
                    return false;
                }

                return $this->payloadParser->isSuccessfulPayment($payload);
            });
    }
}
