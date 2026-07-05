<?php

namespace Tests\Unit;

use App\Models\Order;
use Tests\TestCase;

class OrderProductOrderTest extends TestCase
{
    public function test_is_product_order_id_matches_rde_prefix(): void
    {
        config(['operations.hardware_order_prefix' => 'RDE']);

        $this->assertTrue(Order::isProductOrderId('RDE253851'));
        $this->assertTrue(Order::isProductOrderId('rde123'));
        $this->assertFalse(Order::isProductOrderId('RD-253851'));
        $this->assertFalse(Order::isProductOrderId(null));
    }

    public function test_is_product_order_instance_method(): void
    {
        $order = new Order(['order_id' => 'RDE100']);

        $this->assertTrue($order->isProductOrder());
        $this->assertTrue($order->isHardwareOrderId('RDE100'));
    }
}
