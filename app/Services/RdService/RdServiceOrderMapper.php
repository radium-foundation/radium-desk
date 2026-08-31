<?php

namespace App\Services\RdService;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\RadiumBox\RadiumBoxOrderSearchResponseMapper;

class RdServiceOrderMapper
{
    public function __construct(
        private readonly RadiumBoxOrderSearchResponseMapper $adminMapper,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload, string $expectedOrderId): RadiumBoxOrderEnrichment
    {
        $status = $payload['status'] ?? null;

        if ($status === 404) {
            throw new RadiumBoxOrderNotFoundException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RDService order not found.',
            );
        }

        if ($status !== 200) {
            throw new RadiumBoxInvalidResponseException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'Unexpected RDService response status.',
            );
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new RadiumBoxInvalidResponseException('RDService response is missing data.');
        }

        $rdOrder = $data['rd_order'] ?? null;

        if (! is_array($rdOrder)) {
            throw new RadiumBoxInvalidResponseException('RDService response is missing rd_order data.');
        }

        $this->assertCorrelationMatches($data, $rdOrder, $expectedOrderId);

        $mapped = $this->adminMapper->map(
            $payload,
            $this->adminExpectedOrderId($data, $rdOrder, $expectedOrderId),
        );

        return $this->overlaySnapshot($mapped, is_array($data['snapshot'] ?? null) ? $data['snapshot'] : []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rdOrder
     */
    private function assertCorrelationMatches(array $data, array $rdOrder, string $expectedOrderId): void
    {
        $correlation = $this->correlation($data);

        if (! RdServiceOrderCorrelation::matches(
            $expectedOrderId,
            $this->stringId($correlation['customer_order_id'] ?? null),
            $this->stringId($correlation['cashfree_order_id'] ?? null),
            $this->storedProviderIds($correlation, $rdOrder),
            $rdOrder['id'] ?? null,
        )) {
            throw new RadiumBoxOrderNotFoundException(
                'RDService returned data for a different order.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rdOrder
     */
    private function adminExpectedOrderId(array $data, array $rdOrder, string $expectedOrderId): string
    {
        $correlation = $this->correlation($data);

        return RdServiceOrderCorrelation::adminExpectedOrderId(
            $expectedOrderId,
            $this->stringId($correlation['customer_order_id'] ?? null),
            $this->stringId($correlation['cashfree_order_id'] ?? null),
            $this->storedProviderIds($correlation, $rdOrder),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function correlation(array $data): array
    {
        return is_array($data['correlation'] ?? null) ? $data['correlation'] : [];
    }

    /**
     * Stored provider rdorderid candidates. Kept distinct from customer_order_id
     * and cashfree_order_id — never collapsed with ??.
     *
     * @param  array<string, mixed>  $correlation
     * @param  array<string, mixed>  $rdOrder
     * @return list<string|null>
     */
    private function storedProviderIds(array $correlation, array $rdOrder): array
    {
        return [
            $this->stringId($correlation['rdorderid'] ?? null),
            $this->stringId($rdOrder['rdorderid'] ?? null),
            $this->stringId($rdOrder['order_id'] ?? null),
        ];
    }

    private function stringId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function overlaySnapshot(RadiumBoxOrderEnrichment $mapped, array $snapshot): RadiumBoxOrderEnrichment
    {
        $amc = $mapped->amc
            ?? $this->adminMapper->normalizeOptionalString($snapshot['amc_service'] ?? null)
            ?? $this->adminMapper->normalizeOptionalString($snapshot['amc_status'] ?? null);

        $serviceHistory = $mapped->serviceHistory
            ?? $this->adminMapper->normalizeHistory($snapshot['rd_service'] ?? null);

        return new RadiumBoxOrderEnrichment(
            serialNumber: $mapped->serialNumber
                ?? $this->adminMapper->normalizeSerialNumber($snapshot['serial_number'] ?? null),
            deviceModel: $mapped->deviceModel
                ?? $this->adminMapper->normalizeDeviceModel($snapshot['model'] ?? $snapshot['product'] ?? null),
            activationYear: $mapped->activationYear,
            warranty: $mapped->warranty,
            amc: $amc,
            radiumboxPaymentStatus: $mapped->radiumboxPaymentStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['payment_status'] ?? null),
            radiumboxOrderStatus: $mapped->radiumboxOrderStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['rd_order_status'] ?? null),
            customerName: $mapped->customerName
                ?? $this->adminMapper->normalizeOptionalString($snapshot['customer_name'] ?? null),
            customerPhone: $mapped->customerPhone
                ?? $this->adminMapper->normalizeOptionalString($snapshot['phone'] ?? null),
            customerEmail: $mapped->customerEmail
                ?? $this->normalizeEmail($snapshot['email'] ?? null),
            gstNumber: $mapped->gstNumber
                ?? $this->adminMapper->normalizeOptionalString($snapshot['gst_number'] ?? null),
            invoiceNumber: $mapped->invoiceNumber
                ?? $this->adminMapper->normalizeOptionalString($snapshot['invoice_number'] ?? null),
            purchaseYear: $mapped->purchaseYear,
            serviceHistory: $serviceHistory,
            amcStatus: $mapped->amcStatus ?? $amc,
            amcYear: $mapped->amcYear,
            amcDetails: $mapped->amcDetails ?? $this->amcDetailsFromSnapshot($snapshot, $amc),
            legacyOrderStatus: $mapped->legacyOrderStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['rd_order_status'] ?? null),
            legacyOrderDate: $mapped->legacyOrderDate,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function amcDetailsFromSnapshot(array $snapshot, ?string $amc): ?array
    {
        $summary = [];

        if (filled($amc)) {
            $summary['status'] = $amc;
        }

        $serviceName = $this->adminMapper->normalizeOptionalString($snapshot['amc_service'] ?? null);

        if (filled($serviceName)) {
            $summary['service_name'] = $serviceName;
        }

        return $summary !== [] ? $summary : null;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = $this->adminMapper->normalizeOptionalString($value);

        return $normalized !== null ? strtolower($normalized) : null;
    }
}
