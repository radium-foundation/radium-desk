<?php

namespace Tests\Unit\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Services\Shipping\HttpShiprocketGateway;
use App\Services\Shipping\NullShiprocketGateway;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class ShiprocketGatewayContractTest extends TestCase
{
    /**
     * @return list<class-string<ShiprocketGateway>>
     */
    private function gatewayImplementations(): array
    {
        return [
            NullShiprocketGateway::class,
            HttpShiprocketGateway::class,
            FakeShiprocketGateway::class,
        ];
    }

    #[Test]
    public function test_interface_declares_bulk_document_methods_used_by_hardware_bulk_service(): void
    {
        $interface = new ReflectionClass(ShiprocketGateway::class);

        $this->assertTrue($interface->hasMethod('generateLabelBatch'));
        $this->assertTrue($interface->hasMethod('generateManifestBatch'));
    }

    #[Test]
    public function test_all_gateway_implementations_satisfy_shiprocket_gateway_contract(): void
    {
        $interface = new ReflectionClass(ShiprocketGateway::class);

        foreach ($this->gatewayImplementations() as $class) {
            $implementation = new ReflectionClass($class);
            $this->assertTrue(
                $implementation->implementsInterface(ShiprocketGateway::class),
                $class.' must implement ShiprocketGateway.',
            );

            foreach ($interface->getMethods() as $method) {
                $this->assertTrue(
                    $implementation->hasMethod($method->getName()),
                    $class.' is missing '.$method->getName().'().',
                );
            }
        }
    }
}
