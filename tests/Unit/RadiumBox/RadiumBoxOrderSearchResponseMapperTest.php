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
                ],
            ],
        ]);

        $this->assertSame('M250546898', $enrichment->serialNumber);
        $this->assertSame('Access FM220U L1', $enrichment->deviceModel);
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
