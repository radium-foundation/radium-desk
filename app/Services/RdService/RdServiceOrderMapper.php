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

        $mapped = $this->adminMapper->map($payload, $expectedOrderId);
        $overlaid = $this->overlaySnapshot($mapped, is_array($data['snapshot'] ?? null) ? $data['snapshot'] : []);

        return $this->withLinkedOrderIds($overlaid, $data, $expectedOrderId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rdOrder
     */
    private function assertCorrelationMatches(array $data, array $rdOrder, string $expectedOrderId): void
    {
        $correlation = is_array($data['correlation'] ?? null) ? $data['correlation'] : [];

        $responseOrderId = $correlation['rdorderid']
            ?? $correlation['cashfree_order_id']
            ?? $rdOrder['rdorderid']
            ?? $rdOrder['order_id']
            ?? null;

        if (! is_string($responseOrderId) || trim($responseOrderId) === '') {
            return;
        }

        if (strcasecmp(trim($responseOrderId), trim($expectedOrderId)) !== 0) {
            throw new RadiumBoxOrderNotFoundException(
                'RDService returned data for a different order.',
            );
        }
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
            linkedOrderIds: $mapped->linkedOrderIds,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function withLinkedOrderIds(
        RadiumBoxOrderEnrichment $mapped,
        array $data,
        string $expectedOrderId,
    ): RadiumBoxOrderEnrichment {
        return new RadiumBoxOrderEnrichment(
            serialNumber: $mapped->serialNumber,
            deviceModel: $mapped->deviceModel,
            activationYear: $mapped->activationYear,
            warranty: $mapped->warranty,
            amc: $mapped->amc,
            radiumboxPaymentStatus: $mapped->radiumboxPaymentStatus,
            radiumboxOrderStatus: $mapped->radiumboxOrderStatus,
            customerName: $mapped->customerName,
            customerPhone: $mapped->customerPhone,
            customerEmail: $mapped->customerEmail,
            gstNumber: $mapped->gstNumber,
            invoiceNumber: $mapped->invoiceNumber,
            purchaseYear: $mapped->purchaseYear,
            serviceHistory: $mapped->serviceHistory,
            amcStatus: $mapped->amcStatus,
            amcYear: $mapped->amcYear,
            amcDetails: $mapped->amcDetails,
            legacyOrderStatus: $mapped->legacyOrderStatus,
            legacyOrderDate: $mapped->legacyOrderDate,
            linkedOrderIds: $this->linkedOrderIds($data, $expectedOrderId),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function linkedOrderIds(array $data, string $expectedOrderId): array
    {
        $correlation = is_array($data['correlation'] ?? null) ? $data['correlation'] : [];
        $order = is_array($data['order'] ?? null) ? $data['order'] : [];
        $snapshot = is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [];
        $rdOrder = is_array($data['rd_order'] ?? null) ? $data['rd_order'] : [];

        $ids = [];
        foreach ([
            $expectedOrderId,
            $correlation['rdorderid'] ?? null,
            $correlation['ordercode'] ?? null,
            $correlation['customer_order_id'] ?? null,
            $rdOrder['rdorderid'] ?? null,
            $rdOrder['order_id'] ?? null,
            $order['ordercode'] ?? null,
            $order['rdservice_order_id'] ?? null,
            $snapshot['rdorderid'] ?? null,
            $snapshot['ordercode'] ?? null,
        ] as $value) {
            foreach ($this->normalizeLinkedOrderIds($value) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function normalizeLinkedOrderIds(mixed $value): array
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $upper = strtoupper($value);
        if (preg_match('/^(RD|RDE|RIN|RA)[0-9A-Z]{3,20}$/', $upper) === 1) {
            return [$upper];
        }

        if (preg_match('/^[0-9]{4,20}$/', $value) === 1) {
            return ['RD'.$value];
        }

        return [];
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
