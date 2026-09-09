<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay;
use Tests\TestCase;

class HardwareAllocatedSerialDisplayTest extends TestCase
{
    public function test_qty_one_is_the_serial_without_a_remainder(): void
    {
        $this->assertSame('10532347', HardwareAllocatedSerialDisplay::compact(['10532347'], 1));
        $this->assertSame('10532347', HardwareAllocatedSerialDisplay::compact(['10532347'], null));
        $this->assertStringNotContainsString('+0', HardwareAllocatedSerialDisplay::compact(['10532347'], 1));
        $this->assertTrue(HardwareAllocatedSerialDisplay::isComplete(['10532347'], 1));
    }

    public function test_qty_greater_than_one_uses_first_serial_and_remainder(): void
    {
        $serials = [
            '10532347',
            '10556040',
            '10561005',
            '10553573',
            '10556002',
            '10532464',
            '10566561',
            '10566466',
            '10562862',
            '10553763',
        ];

        $this->assertSame('10532347 +9', HardwareAllocatedSerialDisplay::compact($serials, 10));
        $this->assertTrue(HardwareAllocatedSerialDisplay::isComplete($serials, 10));
        $copy = HardwareAllocatedSerialDisplay::copyValue($serials);
        $this->assertSame(implode("\n", $serials), $copy);
        $this->assertSame($serials, explode("\n", $copy));
        $this->assertStringNotContainsString('+9', $copy);
        $this->assertStringNotContainsString('Allocated Serials', $copy);
        $this->assertSame('Copied 10 serials', HardwareAllocatedSerialDisplay::copyToast($serials));
        $this->assertSame(10, count(HardwareAllocatedSerialDisplay::normalize($serials)));
        $this->assertSame($serials, HardwareAllocatedSerialDisplay::normalize($serials));
    }

    public function test_mismatch_is_explicit_and_not_presented_as_complete(): void
    {
        $this->assertSame(
            'Serials: 9 / 10 allocated',
            HardwareAllocatedSerialDisplay::compact(array_fill(0, 9, 'SN'), 10),
        );
        $this->assertSame(
            'Serials: 11 / 10 allocated',
            HardwareAllocatedSerialDisplay::compact(array_fill(0, 11, 'SN'), 10),
        );
        $this->assertFalse(HardwareAllocatedSerialDisplay::isComplete(['A', 'B'], 10));
        $this->assertFalse(HardwareAllocatedSerialDisplay::isComplete(array_fill(0, 11, 'SN'), 10));
    }
}
