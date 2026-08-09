<?php

namespace App\Services\Cashfree;

use App\Services\Cashfree\Exceptions\CashfreeApiException;

class CashfreeWebhookPayloadFactory
{
    /**
     * Map Cashfree Get Order + SUCCESS payment entities to a Desk webhook payload.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    public function fromOrderAndSuccessPayment(array $order, array $payment): array
    {
        $orderId = $this->scalar($order['order_id'] ?? null);
        $cfPaymentId = $this->scalar($payment['cf_payment_id'] ?? null);
        $paymentStatus = strtoupper((string) ($this->scalar($payment['payment_status'] ?? null) ?? ''));

        if ($orderId === null) {
            throw new CashfreeApiException('Cashfree order payload is missing order_id.');
        }

        if ($cfPaymentId === null) {
            throw new CashfreeApiException('Cashfree payment payload is missing cf_payment_id.');
        }

        if ($paymentStatus !== CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS) {
            throw new CashfreeApiException(
                'Cashfree payment for '.$orderId.' is not SUCCESS (got '.$paymentStatus.').',
            );
        }

        $paymentTime = $this->scalar($payment['payment_time'] ?? null)
            ?? $this->scalar($payment['payment_completion_time'] ?? null);

        $customer = is_array($order['customer_details'] ?? null)
            ? $order['customer_details']
            : [];

        $gateway = is_array($payment['payment_gateway_details'] ?? null)
            ? $payment['payment_gateway_details']
            : [];

        $orderTags = $order['order_tags'] ?? null;
        if (! is_array($orderTags)) {
            $orderTags = null;
        }

        $payload = [
            'type' => CashfreeWebhookPayloadParser::EVENT_PAYMENT_SUCCESS,
            'event_time' => $paymentTime,
            'data' => [
                'order' => array_filter([
                    'order_id' => $orderId,
                    'order_amount' => $order['order_amount'] ?? null,
                    'order_currency' => $this->scalar($order['order_currency'] ?? null) ?? 'INR',
                    'cf_order_id' => $this->scalar($order['cf_order_id'] ?? null),
                    'order_status' => $this->scalar($order['order_status'] ?? null),
                    'order_tags' => $orderTags,
                ], static fn (mixed $value): bool => $value !== null),
                'payment' => array_filter([
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS,
                    'payment_amount' => $payment['payment_amount'] ?? $order['order_amount'] ?? null,
                    'payment_currency' => $this->scalar($payment['payment_currency'] ?? null) ?? 'INR',
                    'payment_time' => $paymentTime,
                    'payment_group' => $this->scalar($payment['payment_group'] ?? null),
                    'bank_reference' => $this->scalar($payment['bank_reference'] ?? null),
                    'payment_message' => $this->scalar($payment['payment_message'] ?? null),
                    'payment_method' => $payment['payment_method'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                'customer_details' => array_filter([
                    'customer_name' => $this->scalar($customer['customer_name'] ?? null),
                    'customer_email' => $this->scalar($customer['customer_email'] ?? null),
                    'customer_phone' => $this->scalar($customer['customer_phone'] ?? null),
                    'customer_id' => $this->scalar($customer['customer_id'] ?? null),
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];

        $gatewayDetails = array_filter([
            'gateway_name' => $this->scalar($gateway['gateway_name'] ?? null) ?? 'CASHFREE',
            'gateway_order_id' => $this->scalar($gateway['gateway_order_id'] ?? null),
            'gateway_payment_id' => $this->scalar($gateway['gateway_payment_id'] ?? null)
                ?? $cfPaymentId,
        ], static fn (mixed $value): bool => $value !== null);

        if ($gatewayDetails !== []) {
            $payload['data']['payment_gateway_details'] = $gatewayDetails;
        }

        return $payload;
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
