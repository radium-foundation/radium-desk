<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareShipmentCollectionMode;
use App\Models\HardwareFulfilment;
use App\Services\HardwareFulfilment\HardwareShipmentCollectionModeResolver;
use PHPUnit\Framework\TestCase;

class HardwareShipmentCollectionModeTest extends TestCase
{
    public function test_prepaid_is_the_enabled_hardware_mode(): void
    {
        $prepaid = HardwareShipmentCollectionMode::Prepaid;
        $cod = HardwareShipmentCollectionMode::Cod;

        $this->assertSame(0, $prepaid->serviceabilityCod());
        $this->assertSame('Prepaid', $prepaid->providerPaymentMethod());
        $this->assertSame('Prepaid', $prepaid->label());
        $this->assertTrue($prepaid->isEnabledForHardware());

        $this->assertSame(1, $cod->serviceabilityCod());
        $this->assertSame('COD', $cod->providerPaymentMethod());
        $this->assertFalse($cod->isEnabledForHardware());
    }

    public function test_resolver_does_not_infer_cod_from_fulfilment_or_courier_capability(): void
    {
        $resolver = new HardwareShipmentCollectionModeResolver;
        $fulfilment = $this->createStub(HardwareFulfilment::class);

        $this->assertSame(HardwareShipmentCollectionMode::Prepaid, $resolver->current());
        $this->assertSame(HardwareShipmentCollectionMode::Prepaid, $resolver->forFulfilment($fulfilment));
        $this->assertSame(0, $resolver->forFulfilment($fulfilment)->serviceabilityCod());
    }
}
