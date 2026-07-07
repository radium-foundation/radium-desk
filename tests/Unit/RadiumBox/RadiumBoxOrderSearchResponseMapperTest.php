<?php

namespace Tests\Unit\RadiumBox;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\RadiumBox\RadiumBoxOrderSearchResponseMapper;
use Tests\TestCase;

class RadiumBoxOrderSearchResponseMapperTest extends TestCase
{
    private RadiumBoxOrderSearchResponseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new RadiumBoxOrderSearchResponseMapper;
    }

    public function test_it_maps_serial_number_and_device_model_from_rd_order(): void
    {
        $enrichment = $this->mapper->map([
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'serial_no' => ' m250546898 ',
                    'product_name' => 'Access FM220U L1',
                    'activation_year' => '2024',
                    'warranty' => 'Active',
                    'amc' => 'Expired',
                ],
            ],
        ]);

        $this->assertSame('M250546898', $enrichment->serialNumber);
        $this->assertSame('Access FM220U L1', $enrichment->deviceModel);
        $this->assertSame('2024', $enrichment->activationYear);
        $this->assertSame('Active', $enrichment->warranty);
        $this->assertSame('Expired', $enrichment->amc);
    }

    public function test_it_maps_legacy_import_fields_from_rd_order(): void
    {
        $enrichment = $this->mapper->map([
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'order_id' => 'RD3395988',
                    'customer_name' => 'Satyam Test',
                    'mobile' => '9876543210',
                    'email' => 'Test@Example.com',
                    'product_name' => 'MFS 110',
                    'serial_no' => 'SN123456',
                    'gst_number' => 'GSTIN123',
                    'invoice_number' => 'INV-9988',
                    'activation_year' => '2022',
                    'service_history' => ['2023', '2024'],
                    'amc_status' => 'Active',
                    'amc_year' => '2025',
                    'order_status' => 'Completed',
                ],
            ],
        ], 'RD3395988');

        $this->assertSame('Satyam Test', $enrichment->customerName);
        $this->assertSame('9876543210', $enrichment->customerPhone);
        $this->assertSame('test@example.com', $enrichment->customerEmail);
        $this->assertSame('GSTIN123', $enrichment->gstNumber);
        $this->assertSame('INV-9988', $enrichment->invoiceNumber);
        $this->assertSame(['2023', '2024'], $enrichment->serviceHistory);
        $this->assertSame('Active', $enrichment->amcStatus);
        $this->assertSame('2025', $enrichment->amcYear);
        $this->assertSame('Completed', $enrichment->legacyOrderStatus);
        $this->assertTrue($enrichment->hasLegacyPreviewData());
    }

    public function test_it_maps_device_data_when_radiumbox_payment_status_is_pending(): void
    {
        $enrichment = $this->mapper->map([
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'order_id' => 'RD3433380',
                    'payment_status' => 'pending',
                    'order_status' => 'pending',
                    'serial_no' => '9655721',
                    'product_name' => 'MFS 110',
                ],
            ],
        ], 'RD3433380');

        $this->assertSame('9655721', $enrichment->serialNumber);
        $this->assertSame('MFS 110', $enrichment->deviceModel);
        $this->assertTrue($enrichment->hasPendingRadiumBoxPaymentStatus());
        $this->assertSame('true', $enrichment->supplementalMetadata()['radiumbox_payment_status_ignored'] ?? null);
    }

    public function test_it_rejects_non_matching_order_id_in_rd_order_payload(): void
    {
        $this->expectException(RadiumBoxOrderNotFoundException::class);

        $this->mapper->map([
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'order_id' => 'RD-OTHER',
                    'serial_no' => '9655721',
                    'product_name' => 'MFS 110',
                ],
            ],
        ], 'RD3433380');
    }

    public function test_it_throws_when_order_is_not_found(): void
    {
        $this->expectException(RadiumBoxOrderNotFoundException::class);
        $this->expectExceptionMessage('RD Order not found');

        $this->mapper->map([
            'status' => 404,
            'message' => 'RD Order not found',
        ]);
    }

    public function test_it_throws_when_rd_order_payload_is_missing(): void
    {
        $this->expectException(RadiumBoxInvalidResponseException::class);
        $this->expectExceptionMessage('missing rd_order data');

        $this->mapper->map([
            'status' => 200,
            'data' => [],
        ]);
    }
}
