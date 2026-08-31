<?php

namespace Tests\Unit\RdService;

use App\Services\RdService\RdServiceOrderId;
use Tests\TestCase;

class RdServiceOrderIdTest extends TestCase
{
    public function test_valid_rd_and_ra_order_ids(): void
    {
        $this->assertSame('RD3000003', RdServiceOrderId::normalize('RD3000003'));
        $this->assertSame('RD3000003', RdServiceOrderId::normalize(' RD3000003 '));
        $this->assertSame('RD3506000', RdServiceOrderId::normalize('RD3506000'));
        $this->assertSame('RD3506770T6a9522b8', RdServiceOrderId::normalize('RD3506770T6a9522b8'));
        $this->assertSame('RA3506771', RdServiceOrderId::normalize('RA3506771'));
        $this->assertSame('RA3506771T6a9522b8', RdServiceOrderId::normalize('RA3506771T6a9522b8'));
        $this->assertTrue(RdServiceOrderId::isValid('RDa1'));
        $this->assertTrue(RdServiceOrderId::isValid('RDE1'));
        $this->assertTrue(RdServiceOrderId::isValid('RA3506771'));
    }

    public function test_invalid_rd_order_ids(): void
    {
        foreach (['', 'RD', 'RA', 'rd3000003', 'ra3506771', 'HW1', 'RD-3000003', 'INQ-1', 'RD 3000003', 'RIN1001', null] as $bad) {
            $this->assertNull(RdServiceOrderId::normalize($bad), (string) $bad);
            $this->assertFalse(RdServiceOrderId::isValid($bad), (string) $bad);
        }
    }

    public function test_namespace_and_t_suffix_helpers(): void
    {
        $this->assertSame('RA', RdServiceOrderId::namespacePrefix('RA3506771'));
        $this->assertSame('RA', RdServiceOrderId::namespacePrefix('RA3506771T6a9522b8'));
        $this->assertSame('RD', RdServiceOrderId::namespacePrefix('RD3506000'));
        $this->assertSame('RD', RdServiceOrderId::namespacePrefix('RD3506770T6a9522b8'));
        $this->assertSame('RD', RdServiceOrderId::namespacePrefix('RDE1'));
        $this->assertNull(RdServiceOrderId::namespacePrefix('INQ-1'));
        $this->assertNull(RdServiceOrderId::namespacePrefix('RIN1001'));

        $this->assertSame('3506771', RdServiceOrderId::numericCore('RA3506771'));
        $this->assertSame('3506771', RdServiceOrderId::numericCore('RA3506771T6a9522b8'));
        $this->assertSame('3506770', RdServiceOrderId::numericCore('RD3506770T6a9522b8'));
        $this->assertNull(RdServiceOrderId::numericCore('RDa1'));

        $this->assertFalse(RdServiceOrderId::hasTSuffix('RA3506771'));
        $this->assertTrue(RdServiceOrderId::hasTSuffix('RA3506771T6a9522b8'));
        $this->assertTrue(RdServiceOrderId::hasTSuffix('RD3506770T6a9522b8'));
        $this->assertFalse(RdServiceOrderId::hasTSuffix('RD3506000'));
    }
}
