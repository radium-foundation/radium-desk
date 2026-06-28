<?php

namespace App\Services\Cashfree;

class CashfreeWebhookPayloadParser
{
    public const EVENT_PAYMENT_SUCCESS = 'PAYMENT_SUCCESS_WEBHOOK';

    public const PAYMENT_STATUS_SUCCESS = 'SUCCESS';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isSuccessfulPayment(array $payload): bool
    {
        return $this->eventType($payload) === self::EVENT_PAYMENT_SUCCESS
            && strtoupper($this->paymentStatus($payload) ?? '') === self::PAYMENT_STATUS_SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cfPaymentId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment.cf_payment_id'))
            ?? $this->scalarValue(data_get($payload, 'cf_payment_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function orderId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.order.order_id'))
            ?? $this->scalarValue(data_get($payload, 'order_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function customerName(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer_details.customer_name'))
            ?? $this->scalarValue(data_get($payload, 'customer_details.customer_name'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function customerEmail(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer_details.customer_email'))
            ?? $this->scalarValue(data_get($payload, 'customer_details.customer_email'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function customerPhone(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer_details.customer_phone'))
            ?? $this->scalarValue(data_get($payload, 'customer_details.customer_phone'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function paymentAmount(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment.payment_amount'))
            ?? $this->scalarValue(data_get($payload, 'payment_amount'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function paymentDate(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment.payment_time'))
            ?? $this->scalarValue(data_get($payload, 'payment_time'))
            ?? $this->scalarValue(data_get($payload, 'event_time'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function bankReference(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment.bank_reference'))
            ?? $this->scalarValue(data_get($payload, 'bank_reference'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function gatewayOrderId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment_gateway_details.gateway_order_id'))
            ?? $this->scalarValue(data_get($payload, 'payment_gateway_details.gateway_order_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function gatewayPaymentId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment_gateway_details.gateway_payment_id'))
            ?? $this->scalarValue(data_get($payload, 'payment_gateway_details.gateway_payment_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function paymentMethod(array $payload): ?string
    {
        $paymentGroup = $this->scalarValue(data_get($payload, 'data.payment.payment_group'));

        if ($paymentGroup !== null) {
            return strtoupper($paymentGroup);
        }

        $paymentMethod = data_get($payload, 'data.payment.payment_method');

        if (is_array($paymentMethod)) {
            $method = array_key_first($paymentMethod);

            return $method !== null ? strtoupper((string) $method) : null;
        }

        return $this->scalarValue($paymentMethod);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'type'))
            ?? $this->scalarValue(data_get($payload, 'event'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function paymentStatus(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.payment.payment_status'))
            ?? $this->scalarValue(data_get($payload, 'payment_status'));
    }

    private function scalarValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
