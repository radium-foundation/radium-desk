<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\ShiprocketAwbAssignmentRejection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShiprocketAwbAssignmentRejectionTest extends TestCase
{
    #[Test]
    public function test_courier_not_serviceable_message_is_classified(): void
    {
        $this->assertTrue(ShiprocketAwbAssignmentRejection::isCourierNotServiceable(
            'HTTP 400 — Given courier not serviceable.',
        ));
    }

    #[Test]
    public function test_generic_http_400_is_not_courier_not_serviceable(): void
    {
        $this->assertFalse(ShiprocketAwbAssignmentRejection::isCourierNotServiceable(
            'HTTP 400 — AWB not assigned',
        ));
    }

    #[Test]
    public function test_result_helper_requires_rejected_without_awb(): void
    {
        $result = new ShiprocketAwbResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'HTTP 400 — Given courier not serviceable.',
            retryable: false,
        );

        $this->assertTrue($result->isCourierNotServiceableRejection());
    }

    #[Test]
    public function test_retryable_result_is_not_recovery_eligible(): void
    {
        $result = new ShiprocketAwbResult(
            provider: 'shiprocket',
            status: 'failed',
            error: 'timeout',
            retryable: true,
        );

        $this->assertFalse($result->isCourierNotServiceableRejection());
    }
}
