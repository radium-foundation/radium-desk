<?php

namespace App\Services\RadiumBox;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use Illuminate\Support\Carbon;

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

        $billingOrder = data_get($payload, 'data.order');
        $billingOrder = is_array($billingOrder) ? $billingOrder : null;

        if ($expectedOrderId !== null) {
            $this->assertOrderIdMatches($rdOrder, $expectedOrderId);
        }

        $userDetails = $this->parseUserDetails(
            data_get($rdOrder, 'userdetails'),
            $billingOrder !== null ? data_get($billingOrder, 'userdetails') : null,
        );

        $amcStatus = $this->normalizeOptionalString(
            data_get($rdOrder, 'amc_status')
                ?? data_get($rdOrder, 'amc'),
        );

        $derivedYear = $this->deriveYear(
            data_get($rdOrder, 'created_at'),
            $billingOrder !== null ? data_get($billingOrder, 'orderdate') : null,
        );

        $activationYear = $this->normalizeOptionalString(
            data_get($rdOrder, 'activation_year')
                ?? data_get($rdOrder, 'activationYear')
                ?? data_get($rdOrder, 'year'),
        ) ?? $derivedYear;

        $purchaseYear = $this->normalizeOptionalString(
            data_get($rdOrder, 'purchase_year')
                ?? data_get($rdOrder, 'purchaseYear'),
        ) ?? $derivedYear;

        $serviceHistory = $this->normalizeHistory(
            data_get($rdOrder, 'service_history')
                ?? data_get($rdOrder, 'rd_service_history')
                ?? data_get($rdOrder, 'service_years')
                ?? data_get($rdOrder, 'service_year'),
        );

        if ($serviceHistory === null) {
            $serviceHistory = $this->normalizeHistory(data_get($rdOrder, 'rd_service_name'));
        }

        return new RadiumBoxOrderEnrichment(
            serialNumber: $this->normalizeSerialNumber(data_get($rdOrder, 'serial_no')),
            deviceModel: $this->normalizeDeviceModel(data_get($rdOrder, 'product_name')),
            activationYear: $activationYear,
            warranty: $this->normalizeOptionalString(
                data_get($rdOrder, 'warranty')
                    ?? data_get($rdOrder, 'warranty_status')
                    ?? data_get($rdOrder, 'warranty_expiry'),
            ),
            amc: $amcStatus,
            radiumboxPaymentStatus: $this->normalizeOptionalString(
                data_get($rdOrder, 'payment_status')
                    ?? data_get($rdOrder, 'pay_status')
                    ?? data_get($rdOrder, 'paymentStatus')
                    ?? ($billingOrder !== null ? data_get($billingOrder, 'payment_status') : null),
            ),
            radiumboxOrderStatus: $this->normalizeOptionalString(
                data_get($rdOrder, 'order_status')
                    ?? data_get($rdOrder, 'status')
                    ?? ($billingOrder !== null ? data_get($billingOrder, 'status') : null),
            ),
            customerName: $this->normalizeOptionalString(
                data_get($rdOrder, 'customer_name')
                    ?? data_get($rdOrder, 'name')
                    ?? data_get($rdOrder, 'cust_name')
                    ?? data_get($userDetails, 'name'),
            ),
            customerPhone: $this->normalizeOptionalString(
                data_get($rdOrder, 'mobile')
                    ?? data_get($rdOrder, 'phone')
                    ?? data_get($rdOrder, 'customer_phone')
                    ?? data_get($rdOrder, 'mobile_no')
                    ?? data_get($userDetails, 'phone'),
            ),
            customerEmail: $this->normalizeEmail(
                data_get($rdOrder, 'email')
                    ?? data_get($rdOrder, 'customer_email')
                    ?? data_get($userDetails, 'email'),
            ),
            gstNumber: $this->normalizeOptionalString(
                data_get($rdOrder, 'gst_number')
                    ?? data_get($rdOrder, 'gst_no')
                    ?? data_get($rdOrder, 'gst')
                    ?? ($billingOrder !== null ? data_get($billingOrder, 'gst_no') : null)
                    ?? data_get($userDetails, 'gst_no'),
            ),
            invoiceNumber: $this->normalizeOptionalString(
                data_get($rdOrder, 'invoice_number')
                    ?? data_get($rdOrder, 'invoice_no')
                    ?? data_get($rdOrder, 'invoice')
                    ?? ($billingOrder !== null ? data_get($billingOrder, 'invoicecode') : null),
            ),
            purchaseYear: $purchaseYear,
            serviceHistory: $serviceHistory,
            amcStatus: $amcStatus,
            amcYear: $this->normalizeOptionalString(
                data_get($rdOrder, 'amc_year')
                    ?? data_get($rdOrder, 'amcYear'),
            ),
            amcDetails: $this->normalizeAmcDetails($rdOrder, $amcStatus),
            legacyOrderStatus: $this->normalizeOptionalString(
                data_get($rdOrder, 'order_status')
                    ?? data_get($rdOrder, 'status')
                    ?? ($billingOrder !== null ? data_get($billingOrder, 'status') : null),
            ),
            legacyOrderDate: $this->parseLegacyOrderDate(
                data_get($rdOrder, 'created_at'),
                $billingOrder !== null ? data_get($billingOrder, 'orderdate') : null,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $rdOrder
     * @return array<string, mixed>|null
     */
    private function normalizeAmcDetails(array $rdOrder, ?string $amcStatus): ?array
    {
        $details = $this->decodeAmcDetails(data_get($rdOrder, 'amc_details'));

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

        $amcServiceName = $this->normalizeOptionalString(data_get($rdOrder, 'amc_service_name'));

        if (filled($amcServiceName)) {
            $summary['service_name'] = $amcServiceName;
        }

        return $summary !== [] ? $summary : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeAmcDetails(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value !== [] ? $value : null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    private function parseLegacyOrderDate(mixed $createdAt, mixed $orderDate): ?Carbon
    {
        foreach ([$orderDate, $createdAt] as $value) {
            $parsed = $this->parseDateTime($value);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            return null;
        }

        $timezone = config('app.timezone', 'Asia/Kolkata');

        foreach ([
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd-m-Y H:i:s',
            'd-m-Y h:i A',
            'd-m-Y H:i',
            'd/m/Y H:i:s',
            'd/m/Y h:i A',
        ] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $normalized, $timezone);

                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($normalized, $timezone);
        } catch (\Throwable) {
            return null;
        }
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
            ?? data_get($rdOrder, 'order_no')
            ?? data_get($rdOrder, 'rdorderid');

        if (! is_string($responseOrderId) || trim($responseOrderId) === '') {
            return;
        }

        if (strcasecmp(trim($responseOrderId), trim($expectedOrderId)) !== 0) {
            throw new RadiumBoxOrderNotFoundException(
                'RadiumBox returned data for a different order.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUserDetails(mixed $primary, mixed $fallback = null): array
    {
        foreach ([$primary, $fallback] as $value) {
            $parsed = $this->decodeUserDetails($value);

            if ($parsed !== []) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeUserDetails(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function deriveYear(mixed $createdAt, mixed $orderDate): ?string
    {
        foreach ([$createdAt, $orderDate] as $value) {
            $year = $this->extractYear($value);

            if ($year !== null) {
                return $year;
            }
        }

        return null;
    }

    private function extractYear(mixed $value): ?string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            return null;
        }

        if (preg_match('/^(\d{4})/', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return null;
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
