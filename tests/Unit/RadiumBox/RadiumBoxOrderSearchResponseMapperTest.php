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

    public function test_it_maps_legacy_import_fields_from_production_style_payload(): void
    {
        $enrichment = $this->mapper->map($this->productionStylePayload(), 'RD3395988');

        $this->assertSame('Satyam Test', $enrichment->customerName);
        $this->assertSame('9876543210', $enrichment->customerPhone);
        $this->assertSame('test@example.com', $enrichment->customerEmail);
        $this->assertSame('GSTIN123', $enrichment->gstNumber);
        $this->assertSame('INV-9988', $enrichment->invoiceNumber);
        $this->assertSame('2022', $enrichment->activationYear);
        $this->assertSame('2022', $enrichment->purchaseYear);
        $this->assertSame(['2023', '2024'], $enrichment->serviceHistory);
        $this->assertSame('Active', $enrichment->amcStatus);
        $this->assertSame('2025', $enrichment->amcYear);
        $this->assertSame('Completed', $enrichment->legacyOrderStatus);
        $this->assertTrue($enrichment->hasLegacyPreviewData());
    }

    public function test_it_maps_rd3395988_production_style_payload(): void
    {
        $enrichment = $this->mapper->map($this->rd3395988ProductionStylePayload(), 'RD3395988');

        $this->assertSame('C Balasubramanian', $enrichment->customerName);
        $this->assertSame('8940040243', $enrichment->customerPhone);
        $this->assertSame('sheyamalaonline@gmail.com', $enrichment->customerEmail);
        $this->assertSame('INV6718288', $enrichment->invoiceNumber);
        $this->assertSame('2026', $enrichment->activationYear);
        $this->assertSame('2026', $enrichment->purchaseYear);
        $this->assertSame('MIS100 IRIS', $enrichment->deviceModel);
        $this->assertSame('6183792', $enrichment->serialNumber);
        $this->assertSame(['1 Year Unlimited'], $enrichment->serviceHistory);
        $this->assertSame('Completed', $enrichment->legacyOrderStatus);
        $this->assertNull($enrichment->gstNumber);
        $this->assertNull($enrichment->amcStatus);
    }

    public function test_it_maps_rd3421021_production_style_payload(): void
    {
        $enrichment = $this->mapper->map($this->rd3421021ProductionStylePayload(), 'RD3421021');

        $this->assertSame('INV6731025', $enrichment->invoiceNumber);
        $this->assertSame('MFS110', $enrichment->deviceModel);
        $this->assertSame('9321909', $enrichment->serialNumber);
        $this->assertSame(['regular'], $enrichment->serviceHistory);
        $this->assertSame(['service_name' => '1 Year Standard'], $enrichment->amcDetails);
        $this->assertSame('2026-06-17 10:45:00', $enrichment->legacyOrderDate?->format('Y-m-d H:i:s'));
    }

    public function test_it_falls_back_to_order_userdetails_when_rd_order_userdetails_is_invalid(): void
    {
        $userDetails = json_encode([
            'name' => 'Fallback Customer',
            'phone' => '9000000001',
            'email' => 'fallback@example.com',
        ]);

        $enrichment = $this->mapper->map([
            'status' => 200,
            'data' => [
                'order' => [
                    'userdetails' => $userDetails,
                ],
                'rd_order' => [
                    'rdorderid' => 'RD-FALLBACK-001',
                    'userdetails' => '{invalid json',
                    'serial_no' => 'SER-001',
                    'product_name' => 'MFS 110',
                ],
            ],
        ], 'RD-FALLBACK-001');

        $this->assertSame('Fallback Customer', $enrichment->customerName);
        $this->assertSame('9000000001', $enrichment->customerPhone);
        $this->assertSame('fallback@example.com', $enrichment->customerEmail);
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
                    'rdorderid' => 'RD-OTHER',
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

    /**
     * @return array<string, mixed>
     */
    private function productionStylePayload(string $orderId = 'RD3395988'): array
    {
        $userDetails = json_encode([
            'name' => 'Satyam Test',
            'phone' => '9876543210',
            'email' => 'Test@Example.com',
            'gst_no' => 'GSTIN123',
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV-9988',
                    'orderdate' => '2022-06-15 10:00:00',
                    'userdetails' => $userDetails,
                    'gst_no' => 'GSTIN123',
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'product_name' => 'MFS 110',
                    'serial_no' => 'SN123456',
                    'userdetails' => $userDetails,
                    'activation_year' => '2022',
                    'service_history' => ['2023', '2024'],
                    'amc_status' => 'Active',
                    'amc_year' => '2025',
                    'rd_service_name' => '1 Year Unlimited',
                    'status' => 'Completed',
                    'created_at' => '2022-06-15 10:00:00',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rd3395988ProductionStylePayload(): array
    {
        $userDetails = json_encode([
            'name' => 'C Balasubramanian',
            'phone' => '8940040243',
            'email' => 'sheyamalaonline@gmail.com',
            'gst_no' => null,
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV6718288',
                    'orderdate' => '2026-05-18 15:27:39',
                    'userdetails' => $userDetails,
                    'gst_no' => null,
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => 'RD3395988',
                    'product_name' => 'MIS100 IRIS',
                    'serial_no' => '6183792',
                    'userdetails' => $userDetails,
                    'gst_no' => null,
                    'rd_service_name' => '1 Year Unlimited',
                    'amc_service_id' => null,
                    'amc_service_name' => null,
                    'status' => 'Completed',
                    'created_at' => '2026-05-18 15:27:15',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rd3421021ProductionStylePayload(): array
    {
        $userDetails = json_encode([
            'name' => 'RD3421021 Customer',
            'phone' => '9876543210',
            'email' => 'rd3421021@example.com',
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV6731025',
                    'orderdate' => '17-06-2026 10:45 AM',
                    'userdetails' => $userDetails,
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => 'RD3421021',
                    'product_name' => 'MFS110',
                    'serial_no' => '9321909',
                    'userdetails' => $userDetails,
                    'rd_service_name' => 'regular',
                    'amc_details' => '{"service_name":"1 Year Standard"}',
                    'status' => 'Completed',
                    'created_at' => '2026-06-17 10:45:00',
                ],
            ],
        ];
    }
}
