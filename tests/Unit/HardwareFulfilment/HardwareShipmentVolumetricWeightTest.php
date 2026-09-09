<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareShipmentVolumetricWeight;
use Tests\TestCase;

class HardwareShipmentVolumetricWeightTest extends TestCase
{
    public function test_uses_shiprocket_india_domestic_divisor(): void
    {
        $this->assertSame(5000, HardwareShipmentVolumetricWeight::DIVISOR_CM3_PER_KG);
        $this->assertSame(0.18, HardwareShipmentVolumetricWeight::kilograms(14, 9, 7));
        $this->assertSame(4.8, HardwareShipmentVolumetricWeight::kilograms(40, 30, 20));
    }
}
