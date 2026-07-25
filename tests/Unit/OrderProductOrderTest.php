<?php

namespace Tests\Unit;

use App\Models\Order;
use Tests\TestCase;

class OrderProductOrderTest extends TestCase
{
    public function test_is_product_order_id_matches_rde_prefix(): void
    {
        config(['operations.hardware_order_prefixes' => ['RDE']]);

        $this->assertTrue(Order::isProductOrderId('RDE253851'));
        $this->assertTrue(Order::isProductOrderId('rde123'));
        $this->assertFalse(Order::isProductOrderId('RD-253851'));
        $this->assertFalse(Order::isProductOrderId(null));
    }

    public function test_is_hardware_order_id_matches_rin_prefix(): void
    {
        config(['operations.hardware_order_prefixes' => ['RDE', 'RIN']]);

        $this->assertTrue(Order::isHardwareOrderId('RIN3460196'));
        $this->assertTrue(Order::isHardwareOrderId('RIN9999999'));
        $this->assertTrue(Order::isHardwareOrderId('RIN0000001'));
        $this->assertTrue(Order::isHardwareOrderId('rin1234567'));
        $this->assertFalse(Order::isHardwareOrderId('RD-3460196'));
        $this->assertFalse(Order::isHardwareOrderId('XRIN123'));
    }

    public function test_existing_hardware_prefixes_remain_hardware(): void
    {
        config(['operations.hardware_order_prefixes' => ['RDE']]);

        $this->assertTrue(Order::isHardwareOrderId('RDE253851'));
        $this->assertTrue(Order::isHardwareOrderId('RDE-10001'));
    }

    public function test_non_rin_non_config_prefix_orders_are_not_hardware(): void
    {
        config(['operations.hardware_order_prefixes' => ['RDE']]);

        $this->assertFalse(Order::isHardwareOrderId('RD-253851'));
        $this->assertFalse(Order::isHardwareOrderId('FM220100'));
    }

    public function test_is_product_order_instance_method(): void
    {
        $order = new Order(['order_id' => 'RDE100']);

        $this->assertTrue($order->isProductOrder());
        $this->assertTrue($order->isHardwareOrderId('RDE100'));
    }
}
