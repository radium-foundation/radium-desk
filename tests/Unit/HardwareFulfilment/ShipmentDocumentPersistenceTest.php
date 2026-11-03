<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\Shipment;
use App\Services\HardwareFulfilment\ShipmentDocumentPersistence;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShipmentDocumentPersistenceTest extends TestCase
{
    #[Test]
    public function test_persistence_failure_returns_validation_instead_of_uncaught_query_exception(): void
    {
        Log::spy();

        $result = new ShiprocketDocumentResult(
            provider: 'test',
            status: 'generated',
            url: 'https://example.test/manifest.pdf',
            documentId: 'MF-1',
        );

        $shipment = Mockery::mock(Shipment::class)->makePartial();
        $shipment->id = 42;
        $shipment->external_shipment_id = '12345';
        $shipment->manifest_id = null;
        $shipment->shouldReceive('forceFill')->andReturnSelf();
        $shipment->shouldReceive('save')->andThrow(new QueryException(
            'mysql',
            'update shipments set manifest_url = ?',
            [],
            new \PDOException('22001', 22001),
        ));

        try {
            ShipmentDocumentPersistence::persistManifest($shipment, $result);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipping', $exception->errors());
            $this->assertStringContainsString('could not save', strtolower($exception->errors()['shipping'][0]));
        }

        Log::shouldHaveReceived('warning')->once();
    }
}
