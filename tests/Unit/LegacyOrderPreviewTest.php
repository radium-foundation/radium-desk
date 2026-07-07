<?php

namespace Tests\Unit;

use App\Data\LegacyOrderPreview;
use PHPUnit\Framework\TestCase;

class LegacyOrderPreviewTest extends TestCase
{
    public function test_complete_preview_is_eligible_for_one_click(): void
    {
        $preview = new LegacyOrderPreview(
            orderId: 'RD3421021',
            customerName: 'Satyam Test',
            mobile: '9876543210',
            productModel: 'MFS 110',
            serialNumber: 'SN123456',
        );

        $this->assertTrue($preview->isCompleteForOneClick());
        $this->assertSame([], $preview->missingFieldsForOneClick());
    }

    public function test_intake_phone_can_satisfy_missing_mobile(): void
    {
        $preview = new LegacyOrderPreview(
            orderId: 'RD3421021',
            customerName: 'Satyam Test',
            mobile: null,
            productModel: 'MFS 110',
            serialNumber: 'SN123456',
        );

        $this->assertFalse($preview->isCompleteForOneClick());
        $this->assertTrue($preview->isCompleteForOneClick('9876543210'));
        $this->assertSame(['mobile'], $preview->missingFieldsForOneClick());
        $this->assertSame([], $preview->missingFieldsForOneClick('9876543210'));
    }

    public function test_missing_serial_marks_preview_incomplete(): void
    {
        $preview = new LegacyOrderPreview(
            orderId: 'RD3421021',
            customerName: 'Satyam Test',
            mobile: '9876543210',
            productModel: 'MFS 110',
            serialNumber: null,
        );

        $this->assertFalse($preview->isCompleteForOneClick());
        $this->assertSame(['serial_number'], $preview->missingFieldsForOneClick());
    }
}
