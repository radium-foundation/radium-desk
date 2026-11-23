<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareShipmentDocumentsService;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use Tests\TestCase;

class HardwareShipmentServiceContainerTest extends TestCase
{
    public function test_hardware_shipment_services_resolve_without_circular_dependency(): void
    {
        $documents = $this->app->make(HardwareShipmentDocumentsService::class);
        $shipments = $this->app->make(HardwareShipmentService::class);

        $this->assertInstanceOf(HardwareShipmentDocumentsService::class, $documents);
        $this->assertInstanceOf(HardwareShipmentService::class, $shipments);
    }
}
