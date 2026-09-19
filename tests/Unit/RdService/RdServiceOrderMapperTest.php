<?php

namespace Tests\Unit\RdService;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\RadiumBox\RadiumBoxOrderSearchResponseMapper;
use App\Services\RdService\RdServiceOrderMapper;
use Tests\TestCase;

class RdServiceOrderMapperTest extends TestCase
{
    private RdServiceOrderMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new RdServiceOrderMapper(new RadiumBoxOrderSearchResponseMapper);
    }

    public function test_it_maps_desk_fields_from_rdservice_payload(): void
    {
        $enrichment = $this->mapper->map($this->payload(), 'RD3000003');

        $this->assertSame('SN1', $enrichment->serialNumber);
        $this->assertSame('MFS110', $enrichment->deviceModel);
        $this->assertSame(['1 Year'], $enrichment->serviceHistory);
        $this->assertSame('Payer', $enrichment->customerName);
        $this->assertSame('payer@example.com', $enrichment->customerEmail);
        $this->assertSame('9999999999', $enrichment->customerPhone);
        $this->assertSame('07ABCDE1234F1Z5', $enrichment->gstNumber);
        $this->assertSame('INV-1', $enrichment->invoiceNumber);
        $this->assertSame('AMC', $enrichment->amcStatus);
        $this->assertSame('Processing', $enrichment->legacyOrderStatus);
        $this->assertTrue($enrichment->hasLegacyPreviewData());
    }

    public function test_it_rejects_correlation_mismatch(): void
    {
        $this->expectException(RadiumBoxOrderNotFoundException::class);

        $payload = $this->payload();
        $payload['data']['correlation']['rdorderid'] = 'RD9999999';
        $payload['data']['rd_order']['rdorderid'] = 'RD9999999';
        $payload['data']['rd_order']['order_id'] = 'RD9999999';

        $this->mapper->map($payload, 'RD3000003');
    }

    public function test_it_rejects_missing_rd_order(): void
    {
        $this->expectException(RadiumBoxInvalidResponseException::class);

        $this->mapper->map([
            'status' => 200,
            'data' => ['correlation' => ['rdorderid' => 'RD3000003']],
        ], 'RD3000003');
    }

    public function test_it_maps_rde_ecom_hardware_fields_from_spoke_payload(): void
    {
        $enrichment = $this->mapper->map($this->rdeEcomPayload(), 'RDE177816');

        $this->assertSame('Mantra MFS 100 / 110 L1 Fingerprint Scanner', $enrichment->deviceModel);
        $this->assertSame('10024774', $enrichment->serialNumber);
        $this->assertSame('Pallab Mukherjee', $enrichment->customerName);
        $this->assertSame('9874773752', $enrichment->customerPhone);
        $this->assertSame('Paid', $enrichment->radiumboxPaymentStatus);
        $this->assertSame('cashfree', $enrichment->paymentMethod);
        $this->assertSame('2735', $enrichment->paymentAmount);
        $this->assertSame('IND568704', $enrichment->invoiceNumber);
        $this->assertSame('Shipped', $enrichment->shipmentStatus);
        $this->assertSame('19041860689453', $enrichment->awb);
        $this->assertSame('MFS 110', $enrichment->productVariant);
        $this->assertSame('PMTMFS110Z', $enrichment->productSku);
        $this->assertSame('700150', $enrichment->deliveryAddress['pincode'] ?? null);
        $this->assertTrue($enrichment->deliveryAddress['pincode_profile_mismatch'] ?? false);
        $this->assertTrue($enrichment->hasLegacyPreviewData());
    }

    public function test_it_maps_rde_ecom_without_serial_from_spoke_payload(): void
    {
        $payload = $this->rdeEcomPayload();
        $payload['data']['rd_order']['serial_no'] = null;
        $payload['data']['snapshot']['serial_number'] = null;

        $enrichment = $this->mapper->map($payload, 'RDE177816');

        $this->assertSame('Mantra MFS 100 / 110 L1 Fingerprint Scanner', $enrichment->deviceModel);
        $this->assertNull($enrichment->serialNumber);
    }

    /**
     * @return array<string, mixed>
     */
    private function rdeEcomPayload(): array
    {
        return [
            'status' => 200,
            'spec_version' => '1.0',
            'website_id' => 'radiumbox.com',
            'message' => 'OK',
            'data' => [
                'correlation' => [
                    'rdorderid' => 'RDE177816',
                    'customer_order_id' => 'RDE177816',
                    'cashfree_order_id' => 'RDE177816',
                    'orders_id' => 177816,
                    'ordercode' => 'RDE177816',
                ],
                'rd_order' => [
                    'id' => 177816,
                    'rdorderid' => 'RDE177816',
                    'order_id' => 'RDE177816',
                    'product_name' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                    'serial_no' => '10024774',
                    'status' => 'Shipped',
                    'payment_status' => 'Paid',
                    'userdetails' => json_encode([
                        'name' => 'Pallab Mukherjee',
                        'email' => 'customer@example.com',
                        'phone' => '9874773752',
                    ]),
                ],
                'order' => [
                    'id' => 177816,
                    'ordercode' => 'RDE177816',
                    'invoicecode' => 'IND568704',
                    'payment_status' => 'Paid',
                    'payment_type' => 'cashfree',
                    'total' => '2735',
                    'status' => 'Shipped',
                    'orderdate' => '2026-01-16 21:27:55',
                    'invoice_date' => '2026-01-17 10:20:40',
                ],
                'snapshot' => [
                    'rdorderid' => 'RDE177816',
                    'customer_name' => 'Pallab Mukherjee',
                    'phone' => '9874773752',
                    'product' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                    'model' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                    'product_variant' => 'MFS 110',
                    'product_sku' => 'PMTMFS110Z',
                    'serial_number' => '10024774',
                    'payment_status' => 'Paid',
                    'payment_method' => 'cashfree',
                    'payment_amount' => '2735',
                    'rd_order_status' => 'Shipped',
                    'shipment_status' => 'Shipped',
                    'awb' => '19041860689453',
                    'invoice_number' => 'IND568704',
                    'invoice_date' => '2026-01-17 10:20:40',
                    'order_date' => '2026-01-16 21:27:55',
                    'delivery_address' => [
                        'line' => 'Sukanta Sarani R.K.Pally, Sonarpur',
                        'state' => 'West Bengal',
                        'district' => 'South 24 Parganas',
                        'pincode' => '700150',
                        'source' => 'order_checkout_snapshot',
                        'pincode_profile_mismatch' => true,
                    ],
                ],
                'lines' => [[
                    'id' => 1,
                    'product_name' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'status' => 200,
            'spec_version' => '1.0',
            'website_id' => 'rdservice.net',
            'message' => 'OK',
            'data' => [
                'correlation' => [
                    'rdorderid' => 'RD3000003',
                    'cashfree_order_id' => 'RD3000003',
                    'cashfree_payment_id' => 'cf-pay-1',
                    'orders_id' => 10,
                    'ordercode' => 'RD10',
                ],
                'rd_order' => [
                    'id' => 3,
                    'rdorderid' => 'RD3000003',
                    'order_id' => 'RD3000003',
                    'product_name' => 'MFS110',
                    'rd_service_name' => '1 Year',
                    'amc_service_name' => 'AMC',
                    'serial_no' => 'SN1',
                    'gst_no' => '07ABCDE1234F1Z5',
                    'status' => 'Processing',
                    'payment_status' => 'Paid',
                    'created_at' => '2026-08-30 10:00:00',
                    'userdetails' => json_encode([
                        'name' => 'Payer',
                        'email' => 'payer@example.com',
                        'phone' => '9999999999',
                        'address' => '1 Test Street',
                        'gst_no' => '07ABCDE1234F1Z5',
                    ]),
                ],
                'order' => [
                    'id' => 10,
                    'ordercode' => 'RD10',
                    'invoicecode' => 'INV-1',
                    'payment_status' => 'Paid',
                    'payment_id' => 'cf-pay-1',
                    'total' => '481',
                    'status' => 'Pending',
                    'orderdate' => '2026-08-30 10:00:00',
                ],
                'snapshot' => [
                    'rdorderid' => 'RD3000003',
                    'customer_name' => 'Payer',
                    'email' => 'payer@example.com',
                    'phone' => '9999999999',
                    'gst_number' => '07ABCDE1234F1Z5',
                    'product' => 'MFS110',
                    'model' => 'MFS110',
                    'rd_service' => '1 Year',
                    'amc_service' => 'AMC',
                    'serial_number' => 'SN1',
                    'invoice_number' => 'INV-1',
                    'rd_order_status' => 'Processing',
                    'payment_status' => 'Paid',
                    'address' => '1 Test Street',
                ],
                'history' => [['id' => 1, 'status' => 'Being Processing']],
                'lines' => [['id' => 1, 'product_name' => 'RD Service']],
            ],
        ];
    }
}
