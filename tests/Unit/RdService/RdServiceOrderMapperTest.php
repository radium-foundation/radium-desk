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
