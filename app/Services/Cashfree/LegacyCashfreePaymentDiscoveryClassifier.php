<?php

namespace App\Services\Cashfree;

use App\Enums\LegacyCashfreePaymentDiscoveryClassification;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Services\Cashfree\Exceptions\CashfreeApiException;

/**
 * Read-only classification for legacy Desk orders vs Cashfree PG evidence.
 *
 * Future execution (separate gated prompt) should use:
 * CashfreeWebhookPayloadFactory → CashfreeWebhookLog → CashfreeWebhookProcessorService::process()
 * with explicitly approved desk order IDs; never expand missed-batch heal allowlists blindly.
 */
final class LegacyCashfreePaymentDiscoveryClassifier
{
    /**
     * @param  list<array<string, mixed>>  $payments
     * @param  list<array<string, mixed>>  $refunds
     * @return array{
     *     classification: LegacyCashfreePaymentDiscoveryClassification,
     *     reason: string,
     *     recommended_next_action: string,
     *     customer_correlation: string,
     *     cashfree_order_status: ?string,
     *     cashfree_cf_order_id: ?string,
     *     cashfree_payment_ids: ?string,
     *     cashfree_amount: ?string,
     *     cashfree_payment_status: ?string,
     *     cashfree_payment_group: ?string,
     *     cashfree_payment_date: ?string,
     *     cashfree_refund_status: ?string,
     * }
     */
    public function classify(
        Order $order,
        ?array $cashfreeOrder,
        array $payments,
        array $refunds,
        ?CashfreeApiException $apiError = null,
        bool $refundsLookupFailed = false,
    ): array {
        $deskCf = $this->scalar($order->cashfree_payment_id);
        $deskAmount = $this->decimal($order->payment_amount);

        if ($apiError !== null) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment,
                'Cashfree API lookup failed: '.$this->sanitizeApiMessage($apiError->getMessage()),
                'Verify Cashfree credentials and order id; retry discovery or investigate manually.',
                'unknown',
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $this->refundSummary($refunds),
            );
        }

        if ($cashfreeOrder === null) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment,
                'Cashfree returned no order entity.',
                'Confirm merchant order id or treat as non-Cashfree payment.',
                'unknown',
            );
        }

        $merchantOrderId = $this->scalar($cashfreeOrder['order_id'] ?? null);
        if ($merchantOrderId === null || strtoupper($merchantOrderId) !== strtoupper((string) $order->order_id)) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::Ambiguous,
                'Cashfree order_id does not exactly match Desk order_id.',
                'Manual review required; do not auto-link.',
                'mismatch',
                $this->scalar($cashfreeOrder['order_status'] ?? null),
                $this->scalar($cashfreeOrder['cf_order_id'] ?? null),
            );
        }

        $cfOrderStatus = strtoupper((string) ($this->scalar($cashfreeOrder['order_status'] ?? null) ?? ''));
        $cfOrderId = $this->scalar($cashfreeOrder['cf_order_id'] ?? null);
        $orderAmount = $this->decimal($cashfreeOrder['order_amount'] ?? null);

        if ($refundsLookupFailed) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::Ambiguous,
                'Cashfree refunds lookup failed; refund state is unknown.',
                'Retry discovery or verify refunds manually before any payment link.',
                'unknown',
                $cfOrderStatus,
                $cfOrderId,
                cashfreeRefundStatus: 'refunds_lookup_failed',
            );
        }

        if ($refunds !== []) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::RefundConflict,
                'Cashfree reports one or more refunds for this order.',
                'Finance review before any payment link; refunds may require manual handling.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                null,
                null,
                null,
                null,
                null,
                $this->refundSummary($refunds),
            );
        }

        if ($order->refundRequests()->whereIn('status', $this->blockingRefundStatuses())->exists()) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::RefundConflict,
                'Desk has an active or completed refund request for this order.',
                'Resolve refund workflow before payment reconciliation.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                cashfreeRefundStatus: 'desk_refund_present',
            );
        }

        $successPayments = $this->successfulCapturedPayments($payments);

        if ($deskCf !== null && $deskAmount !== null && $deskAmount > 0) {
            $matching = array_values(array_filter(
                $successPayments,
                fn (array $p): bool => $this->scalar($p['cf_payment_id'] ?? null) === $deskCf,
            ));

            if ($matching !== [] && count($successPayments) === 1) {
                $payAmount = $this->decimal($matching[0]['payment_amount'] ?? null);
                if ($payAmount !== null && abs($payAmount - $deskAmount) < 0.01) {
                    return $this->filledFromPayment(
                        $order,
                        $cashfreeOrder,
                        $matching[0],
                        LegacyCashfreePaymentDiscoveryClassification::AlreadyLinked,
                        'Desk already stores the same Cashfree payment id and amount.',
                        'No action required.',
                        'matched',
                    );
                }
            }

            if ($deskCf !== null && $successPayments !== []) {
                $cfIds = array_map(fn (array $p) => $this->scalar($p['cf_payment_id'] ?? null), $successPayments);
                if (! in_array($deskCf, array_filter($cfIds), true)) {
                    return $this->result(
                        LegacyCashfreePaymentDiscoveryClassification::ConflictingDeskPayment,
                        'Desk cashfree_payment_id does not match Cashfree successful payment.',
                        'Manual finance review; do not auto-link.',
                        $this->customerCorrelation($order, $cashfreeOrder),
                        $cfOrderStatus,
                        $cfOrderId,
                        implode(',', array_filter($cfIds)),
                    );
                }
            }

            if ($deskAmount !== null && $deskAmount > 0 && $successPayments === []) {
                return $this->result(
                    LegacyCashfreePaymentDiscoveryClassification::ConflictingDeskPayment,
                    'Desk records payment but Cashfree has no successful captured payment.',
                    'Manual review.',
                    $this->customerCorrelation($order, $cashfreeOrder),
                    $cfOrderStatus,
                    $cfOrderId,
                );
            }
        }

        if ($cfOrderStatus !== 'PAID') {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment,
                'Cashfree order is not PAID (status: '.($cfOrderStatus ?: 'unknown').').',
                'No payment link; investigate alternate payment channel.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
            );
        }

        if ($successPayments === []) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment,
                'No successful captured Cashfree payment found.',
                'No payment link via Cashfree.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
            );
        }

        if (count($successPayments) > 1) {
            $ids = implode(',', array_map(
                fn (array $p): string => (string) ($this->scalar($p['cf_payment_id'] ?? null) ?? '?'),
                $successPayments,
            ));

            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::MultiplePayments,
                'More than one successful captured Cashfree payment exists.',
                'Finance must select the correct payment; do not auto-link.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                $ids,
            );
        }

        $payment = $successPayments[0];
        $cfPaymentId = $this->scalar($payment['cf_payment_id'] ?? null);
        $payAmount = $this->decimal($payment['payment_amount'] ?? null);
        $currency = strtoupper((string) ($this->scalar($payment['payment_currency'] ?? null) ?? 'INR'));

        if ($cfPaymentId === null) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::Ambiguous,
                'Successful payment is missing cf_payment_id.',
                'Manual review.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
            );
        }

        $otherOrder = Order::query()
            ->where('cashfree_payment_id', $cfPaymentId)
            ->whereKeyNot($order->id)
            ->exists();

        if ($otherOrder) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::ConflictingDeskPayment,
                'Another Desk order already owns this cf_payment_id.',
                'Resolve ownership conflict before linking.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                $cfPaymentId,
            );
        }

        if ($currency !== 'INR') {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::Ambiguous,
                'Cashfree payment currency is not INR.',
                'Manual review.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                $cfPaymentId,
            );
        }

        if ($payAmount === null || $payAmount <= 0) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::Ambiguous,
                'Cashfree payment amount is missing or zero.',
                'Manual review.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                $cfPaymentId,
            );
        }

        if ($orderAmount !== null && abs($orderAmount - $payAmount) >= 0.01) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::AmountMismatch,
                sprintf('Cashfree order_amount (%.2f) differs from payment_amount (%.2f).', $orderAmount, $payAmount),
                'Finance review before link.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                $cfPaymentId,
                (string) $payAmount,
            );
        }

        if ($deskAmount !== null && $deskAmount > 0 && abs($deskAmount - $payAmount) >= 0.01) {
            return $this->result(
                LegacyCashfreePaymentDiscoveryClassification::AmountMismatch,
                sprintf('Desk payment_amount (%.2f) differs from Cashfree (%.2f).', $deskAmount, $payAmount),
                'Manual correction review.',
                $this->customerCorrelation($order, $cashfreeOrder),
                $cfOrderStatus,
                $cfOrderId,
                $cfPaymentId,
                (string) $payAmount,
            );
        }

        $customerCorrelation = $this->customerCorrelation($order, $cashfreeOrder);
        if ($customerCorrelation === 'mismatch') {
            return $this->filledFromPayment(
                $order,
                $cashfreeOrder,
                $payment,
                LegacyCashfreePaymentDiscoveryClassification::CustomerMismatch,
                'Cashfree customer email/phone does not match Desk customer fields.',
                'Verify customer identity before owner-approved link.',
                'mismatch',
            );
        }

        if ($deskCf !== null && $deskCf !== $cfPaymentId) {
            return $this->filledFromPayment(
                $order,
                $cashfreeOrder,
                $payment,
                LegacyCashfreePaymentDiscoveryClassification::ConflictingDeskPayment,
                'Desk has a different cashfree_payment_id than Cashfree success payment.',
                'Manual review.',
                $customerCorrelation,
            );
        }

        return $this->filledFromPayment(
            $order,
            $cashfreeOrder,
            $payment,
            LegacyCashfreePaymentDiscoveryClassification::SafeToLink,
            'Exact order id, single SUCCESS captured INR payment, customer correlation ok, no refund conflict.',
            'Eligible for separate owner/finance-approved existing-order payment link (P-04-10-194 pattern).',
            $customerCorrelation,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array<string, mixed>>
     */
    public function successfulCapturedPayments(array $payments): array
    {
        $success = [];

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $status = strtoupper((string) ($this->scalar($payment['payment_status'] ?? null) ?? ''));
            if ($status !== CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS) {
                continue;
            }

            if (($payment['is_captured'] ?? true) === false) {
                continue;
            }

            if ($this->scalar($payment['cf_payment_id'] ?? null) === null) {
                continue;
            }

            $success[] = $payment;
        }

        return $success;
    }

    /**
     * @param  list<array<string, mixed>>  $refunds
     */
    private function refundSummary(array $refunds): string
    {
        if ($refunds === []) {
            return 'none';
        }

        return 'count:'.count($refunds);
    }

    /**
     * @return list<string>
     */
    private function blockingRefundStatuses(): array
    {
        return array_map(
            fn (RefundStatus $status): string => $status->value,
            array_filter(
                RefundStatus::cases(),
                fn (RefundStatus $status): bool => $status !== RefundStatus::Rejected
                    && $status !== RefundStatus::Revoked,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $cashfreeOrder
     */
    private function customerCorrelation(Order $order, array $cashfreeOrder): string
    {
        $customer = is_array($cashfreeOrder['customer_details'] ?? null)
            ? $cashfreeOrder['customer_details']
            : [];

        $cfEmail = $this->normalizeEmail($customer['customer_email'] ?? null);
        $deskEmail = $this->normalizeEmail($order->customer_email);

        if ($cfEmail !== null && $deskEmail !== null && $cfEmail !== $deskEmail) {
            return 'mismatch';
        }

        $cfPhone = $this->normalizePhoneDigits($customer['customer_phone'] ?? null);
        $deskPhone = $this->normalizePhoneDigits($order->customer_phone);

        if ($cfPhone !== null && $deskPhone !== null && strlen($cfPhone) >= 10 && strlen($deskPhone) >= 10) {
            if (substr($cfPhone, -10) !== substr($deskPhone, -10)) {
                return 'mismatch';
            }
        }

        if ($cfEmail !== null || $cfPhone !== null) {
            return 'matched';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $cashfreeOrder
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function filledFromPayment(
        Order $order,
        array $cashfreeOrder,
        array $payment,
        LegacyCashfreePaymentDiscoveryClassification $classification,
        string $reason,
        string $action,
        string $customerCorrelation,
    ): array {
        $cfPaymentId = $this->scalar($payment['cf_payment_id'] ?? null);
        $payAmount = $this->decimal($payment['payment_amount'] ?? null);

        return $this->result(
            $classification,
            $reason,
            $action,
            $customerCorrelation,
            strtoupper((string) ($this->scalar($cashfreeOrder['order_status'] ?? null) ?? '')),
            $this->scalar($cashfreeOrder['cf_order_id'] ?? null),
            $cfPaymentId,
            $payAmount !== null ? (string) $payAmount : null,
            $this->scalar($payment['payment_status'] ?? null),
            $this->scalar($payment['payment_group'] ?? null),
            $this->scalar($payment['payment_time'] ?? null) ?? $this->scalar($payment['payment_completion_time'] ?? null),
            'none',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        LegacyCashfreePaymentDiscoveryClassification $classification,
        string $reason,
        string $recommendedNextAction,
        string $customerCorrelation,
        ?string $cashfreeOrderStatus = null,
        ?string $cashfreeCfOrderId = null,
        ?string $cashfreePaymentIds = null,
        ?string $cashfreeAmount = null,
        ?string $cashfreePaymentStatus = null,
        ?string $cashfreePaymentGroup = null,
        ?string $cashfreePaymentDate = null,
        ?string $cashfreeRefundStatus = 'none',
    ): array {
        return [
            'classification' => $classification,
            'reason' => $reason,
            'recommended_next_action' => $recommendedNextAction,
            'customer_correlation' => $customerCorrelation,
            'cashfree_order_status' => $cashfreeOrderStatus,
            'cashfree_cf_order_id' => $cashfreeCfOrderId,
            'cashfree_payment_ids' => $cashfreePaymentIds,
            'cashfree_amount' => $cashfreeAmount,
            'cashfree_payment_status' => $cashfreePaymentStatus,
            'cashfree_payment_group' => $cashfreePaymentGroup,
            'cashfree_payment_date' => $cashfreePaymentDate,
            'cashfree_refund_status' => $cashfreeRefundStatus,
        ];
    }

    private function sanitizeApiMessage(string $message): string
    {
        return preg_replace('/\b(secret|password|token|api[_-]?key)\b/i', '[redacted]', $message) ?? $message;
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizePhoneDigits(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : $digits;
    }
}
