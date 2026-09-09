<?php

namespace Tests\Unit\RdService;

use App\Services\RdService\RdServiceOrderId;
use Tests\TestCase;

class RdServiceOrderIdTest extends TestCase
{
    public function test_valid_rd_order_ids(): void
    {
        $this->assertSame('RD3000003', RdServiceOrderId::normalize('RD3000003'));
        $this->assertSame('RD3000003', RdServiceOrderId::normalize(' RD3000003 '));
        $this->assertTrue(RdServiceOrderId::isValid('RDA1'));
        $this->assertFalse(RdServiceOrderId::isValid('RDE1'));
        $this->assertFalse(RdServiceOrderId::isValid('RDP1'));
        $this->assertTrue(RdServiceOrderId::isValid('RA32'));
        $this->assertTrue(RdServiceOrderId::isValid('RN1'));
    }

    public function test_invalid_rd_order_ids(): void
    {
        foreach (['', 'RD', 'rd3000003', 'HW1', 'RD-3000003', 'INQ-1', 'RD 3000003', null] as $bad) {
            $this->assertNull(RdServiceOrderId::normalize($bad), (string) $bad);
            $this->assertFalse(RdServiceOrderId::isValid($bad), (string) $bad);
        }
    }
}
