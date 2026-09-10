<?php

namespace Tests\Unit\Support;

use App\Support\BusinessOrderId;
use Tests\TestCase;

class BusinessOrderIdTest extends TestCase
{
    public function test_longest_prefix_wins(): void
    {
        $this->assertSame('RBP', BusinessOrderId::prefix('RBP12'));
        $this->assertSame('RB', BusinessOrderId::prefix('RB12'));
        $this->assertSame('RDP', BusinessOrderId::prefix('RDP9'));
        $this->assertSame('RD', BusinessOrderId::prefix('RD3511756'));
        $this->assertSame('RSP', BusinessOrderId::prefix('RSP1'));
        $this->assertSame('RS', BusinessOrderId::prefix('RS1'));
        $this->assertNotSame('RB', BusinessOrderId::prefix('RBP1'));
        $this->assertNotSame('RD', BusinessOrderId::prefix('RDP1'));
        $this->assertNotSame('RS', BusinessOrderId::prefix('RSP1'));
    }

    public function test_historical_ids_keep_owners(): void
    {
        $this->assertSame('rdservice.net', BusinessOrderId::owner('RA32'));
        $this->assertSame('radiumsign.com', BusinessOrderId::owner('RDS366'));
        $this->assertSame('radiumbox.com', BusinessOrderId::owner('RDE318516'));
        $this->assertSame('radiumbox.com', BusinessOrderId::owner('RBX3511545'));
        $this->assertSame('rdservice.in', BusinessOrderId::owner('RIN00001'));
        $this->assertSame('rdservice.in', BusinessOrderId::owner('RD3511756'));
    }

    public function test_new_namespaces(): void
    {
        $this->assertSame('radiumbox.com', BusinessOrderId::owner('RB1'));
        $this->assertSame('radiumbox.com', BusinessOrderId::owner('RBP1'));
        $this->assertSame('rdservice.in', BusinessOrderId::owner('RDP1'));
        $this->assertSame('rdservice.net', BusinessOrderId::owner('RN1'));
        $this->assertSame('rdservice.net', BusinessOrderId::owner('RNP1'));
        $this->assertSame('radiumsign.com', BusinessOrderId::owner('RS1'));
        $this->assertSame('radiumsign.com', BusinessOrderId::owner('RSP1'));
    }

    public function test_hardware_is_not_inferred_from_new_product_prefixes(): void
    {
        $this->assertTrue(BusinessOrderId::isHardwareByPrefix('RDE1'));
        $this->assertTrue(BusinessOrderId::isHardwareByPrefix('RIN1'));
        $this->assertTrue(BusinessOrderId::isHardwareByPrefix('RBP1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RDP1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RNP1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RSP1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RB1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RD1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RN1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RS1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RA1'));
        $this->assertFalse(BusinessOrderId::isHardwareByPrefix('RDS1'));
    }
}
