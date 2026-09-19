<?php

namespace App\Services\RdService;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\RadiumBox\RadiumBoxOrderSearchResponseMapper;
use Illuminate\Support\Carbon;

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

        return $this->overlaySnapshot(
            $mapped,
            is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [],
            is_array($data['order'] ?? null) ? $data['order'] : [],
        );
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
     * @param  array<string, mixed>  $order
     */
    private function overlaySnapshot(
        RadiumBoxOrderEnrichment $mapped,
        array $snapshot,
        array $order = [],
    ): RadiumBoxOrderEnrichment {
        $amc = $mapped->amc
            ?? $this->adminMapper->normalizeOptionalString($snapshot['amc_service'] ?? null)
            ?? $this->adminMapper->normalizeOptionalString($snapshot['amc_status'] ?? null);

        $serviceHistory = $mapped->serviceHistory
            ?? $this->adminMapper->normalizeHistory($snapshot['rd_service'] ?? null);

        $invoiceDate = $mapped->invoiceDate
            ?? $this->parseSnapshotDate($snapshot['invoice_date'] ?? null)
            ?? $this->parseSnapshotDate($order['invoice_date'] ?? null);

        $deliveryAddress = $mapped->deliveryAddress ?? $this->normalizeDeliveryAddress($snapshot['delivery_address'] ?? null);

        return new RadiumBoxOrderEnrichment(
            serialNumber: $mapped->serialNumber
                ?? $this->adminMapper->normalizeSerialNumber($snapshot['serial_number'] ?? null),
            deviceModel: $mapped->deviceModel
                ?? $this->adminMapper->normalizeDeviceModel($snapshot['model'] ?? $snapshot['product'] ?? null),
            activationYear: $mapped->activationYear,
            warranty: $mapped->warranty,
            amc: $amc,
            radiumboxPaymentStatus: $mapped->radiumboxPaymentStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['payment_status'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['payment_status'] ?? null),
            radiumboxOrderStatus: $mapped->radiumboxOrderStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['rd_order_status'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['status'] ?? null),
            customerName: $mapped->customerName
                ?? $this->adminMapper->normalizeOptionalString($snapshot['customer_name'] ?? null),
            customerPhone: $mapped->customerPhone
                ?? $this->adminMapper->normalizeOptionalString($snapshot['phone'] ?? null),
            customerEmail: $mapped->customerEmail
                ?? $this->normalizeEmail($snapshot['email'] ?? null),
            gstNumber: $mapped->gstNumber
                ?? $this->adminMapper->normalizeOptionalString($snapshot['gst_number'] ?? null),
            invoiceNumber: $mapped->invoiceNumber
                ?? $this->adminMapper->normalizeOptionalString($snapshot['invoice_number'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['invoicecode'] ?? null),
            purchaseYear: $mapped->purchaseYear,
            serviceHistory: $serviceHistory,
            amcStatus: $mapped->amcStatus ?? $amc,
            amcYear: $mapped->amcYear,
            amcDetails: $mapped->amcDetails ?? $this->amcDetailsFromSnapshot($snapshot, $amc),
            legacyOrderStatus: $mapped->legacyOrderStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['rd_order_status'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['status'] ?? null),
            legacyOrderDate: $mapped->legacyOrderDate
                ?? $this->parseSnapshotDate($snapshot['order_date'] ?? null)
                ?? $this->parseSnapshotDate($order['orderdate'] ?? null),
            paymentMethod: $mapped->paymentMethod
                ?? $this->adminMapper->normalizeOptionalString($snapshot['payment_method'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['payment_type'] ?? null),
            paymentAmount: $mapped->paymentAmount
                ?? $this->adminMapper->normalizeOptionalString($snapshot['payment_amount'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['total'] ?? null),
            invoiceDate: $invoiceDate,
            shipmentStatus: $mapped->shipmentStatus
                ?? $this->adminMapper->normalizeOptionalString($snapshot['shipment_status'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($snapshot['rd_order_status'] ?? null)
                ?? $this->adminMapper->normalizeOptionalString($order['status'] ?? null),
            awb: $mapped->awb
                ?? $this->adminMapper->normalizeOptionalString($snapshot['awb'] ?? null),
            productVariant: $mapped->productVariant
                ?? $this->adminMapper->normalizeOptionalString($snapshot['product_variant'] ?? null),
            productSku: $mapped->productSku
                ?? $this->adminMapper->normalizeOptionalString($snapshot['product_sku'] ?? null),
            deliveryAddress: $deliveryAddress,
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

    private function parseSnapshotDate(mixed $value): ?Carbon
    {
        $normalized = $this->adminMapper->normalizeOptionalString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized, config('app.timezone', 'Asia/Kolkata'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeDeliveryAddress(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $address = [
            'line' => $this->adminMapper->normalizeOptionalString($value['line'] ?? null),
            'state' => $this->adminMapper->normalizeOptionalString($value['state'] ?? null),
            'district' => $this->adminMapper->normalizeOptionalString($value['district'] ?? null),
            'pincode' => $this->adminMapper->normalizeOptionalString($value['pincode'] ?? null),
            'source' => $this->adminMapper->normalizeOptionalString($value['source'] ?? null),
        ];

        if (($value['pincode_profile_mismatch'] ?? false) === true) {
            $address['pincode_profile_mismatch'] = true;
        }

        if (
            $address['line'] === null
            && $address['state'] === null
            && $address['district'] === null
            && $address['pincode'] === null
        ) {
            return null;
        }

        return $address;
    }
}
