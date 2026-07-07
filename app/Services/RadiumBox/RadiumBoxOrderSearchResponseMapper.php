<?php

namespace App\Services\RadiumBox;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;

class RadiumBoxOrderSearchResponseMapper
{
    public function map(array $payload, ?string $expectedOrderId = null): RadiumBoxOrderEnrichment
    {
        $status = $payload['status'] ?? null;

        if ($status === 404) {
            throw new RadiumBoxOrderNotFoundException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox order not found.',
            );
        }

        if ($status !== 200) {
            throw new RadiumBoxInvalidResponseException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'Unexpected RadiumBox response status.',
            );
        }

        $rdOrder = data_get($payload, 'data.rd_order');

        if (! is_array($rdOrder)) {
            throw new RadiumBoxInvalidResponseException('RadiumBox response is missing rd_order data.');
        }

        if ($expectedOrderId !== null) {
            $this->assertOrderIdMatches($rdOrder, $expectedOrderId);
        }

        $amcStatus = $this->normalizeOptionalString(
            data_get($rdOrder, 'amc_status')
                ?? data_get($rdOrder, 'amc'),
        );

        return new RadiumBoxOrderEnrichment(
            serialNumber: $this->normalizeSerialNumber(data_get($rdOrder, 'serial_no')),
            deviceModel: $this->normalizeDeviceModel(data_get($rdOrder, 'product_name')),
            activationYear: $this->normalizeOptionalString(
                data_get($rdOrder, 'activation_year')
                    ?? data_get($rdOrder, 'activationYear')
                    ?? data_get($rdOrder, 'year'),
            ),
            warranty: $this->normalizeOptionalString(
                data_get($rdOrder, 'warranty')
                    ?? data_get($rdOrder, 'warranty_status')
                    ?? data_get($rdOrder, 'warranty_expiry'),
            ),
            amc: $amcStatus,
            radiumboxPaymentStatus: $this->normalizeOptionalString(
                data_get($rdOrder, 'payment_status')
                    ?? data_get($rdOrder, 'pay_status')
                    ?? data_get($rdOrder, 'paymentStatus'),
            ),
            radiumboxOrderStatus: $this->normalizeOptionalString(
                data_get($rdOrder, 'order_status')
                    ?? data_get($rdOrder, 'status'),
            ),
            customerName: $this->normalizeOptionalString(
                data_get($rdOrder, 'customer_name')
                    ?? data_get($rdOrder, 'name')
                    ?? data_get($rdOrder, 'cust_name'),
            ),
            customerPhone: $this->normalizeOptionalString(
                data_get($rdOrder, 'mobile')
                    ?? data_get($rdOrder, 'phone')
                    ?? data_get($rdOrder, 'customer_phone')
                    ?? data_get($rdOrder, 'mobile_no'),
            ),
            customerEmail: $this->normalizeEmail(
                data_get($rdOrder, 'email')
                    ?? data_get($rdOrder, 'customer_email'),
            ),
            gstNumber: $this->normalizeOptionalString(
                data_get($rdOrder, 'gst_number')
                    ?? data_get($rdOrder, 'gst_no')
                    ?? data_get($rdOrder, 'gst'),
            ),
            invoiceNumber: $this->normalizeOptionalString(
                data_get($rdOrder, 'invoice_number')
                    ?? data_get($rdOrder, 'invoice_no')
                    ?? data_get($rdOrder, 'invoice'),
            ),
            purchaseYear: $this->normalizeOptionalString(
                data_get($rdOrder, 'purchase_year')
                    ?? data_get($rdOrder, 'purchaseYear'),
            ),
            serviceHistory: $this->normalizeHistory(
                data_get($rdOrder, 'service_history')
                    ?? data_get($rdOrder, 'rd_service_history')
                    ?? data_get($rdOrder, 'service_years')
                    ?? data_get($rdOrder, 'service_year'),
            ),
            amcStatus: $amcStatus,
            amcYear: $this->normalizeOptionalString(
                data_get($rdOrder, 'amc_year')
                    ?? data_get($rdOrder, 'amcYear'),
            ),
            amcDetails: $this->normalizeAmcDetails($rdOrder, $amcStatus),
            legacyOrderStatus: $this->normalizeOptionalString(
                data_get($rdOrder, 'order_status')
                    ?? data_get($rdOrder, 'status'),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $rdOrder
     * @return array<string, mixed>|null
     */
    private function normalizeAmcDetails(array $rdOrder, ?string $amcStatus): ?array
    {
        $details = data_get($rdOrder, 'amc_details');

        if (is_array($details) && $details !== []) {
            return $details;
        }

        $summary = [];

        if (filled($amcStatus)) {
            $summary['status'] = $amcStatus;
        }

        $amcYear = $this->normalizeOptionalString(
            data_get($rdOrder, 'amc_year')
                ?? data_get($rdOrder, 'amcYear'),
        );

        if (filled($amcYear)) {
            $summary['year'] = $amcYear;
        }

        return $summary !== [] ? $summary : null;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function normalizeHistory(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value !== [] ? array_values($value) : null;
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        if (is_int($value) || is_float($value)) {
            return [(string) $value];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rdOrder
     */
    private function assertOrderIdMatches(array $rdOrder, string $expectedOrderId): void
    {
        $responseOrderId = data_get($rdOrder, 'order_id')
            ?? data_get($rdOrder, 'orderid')
            ?? data_get($rdOrder, 'order_no');

        if (! is_string($responseOrderId) || trim($responseOrderId) === '') {
            return;
        }

        if (strcasecmp(trim($responseOrderId), trim($expectedOrderId)) !== 0) {
            throw new RadiumBoxOrderNotFoundException(
                'RadiumBox returned data for a different order.',
            );
        }
    }

    private function normalizeSerialNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $serialNumber = strtoupper(trim($value));

        return $serialNumber !== '' ? $serialNumber : null;
    }

    private function normalizeDeviceModel(mixed $value): ?string
    {
        return $this->normalizeOptionalString($value);
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = $this->normalizeOptionalString($value);

        return $normalized !== null ? strtolower($normalized) : null;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
