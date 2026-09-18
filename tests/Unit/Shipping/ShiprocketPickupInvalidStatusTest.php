<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\Data\ShiprocketPickupResult;
use App\Services\Shipping\ShiprocketPickupInvalidStatus;
use Tests\TestCase;

class ShiprocketPickupInvalidStatusTest extends TestCase
{
    public function test_invalid_status_phrase_matches_rejected_pickup_result(): void
    {
        $result = new ShiprocketPickupResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'HTTP 400 — Invalid Status for pickup generation',
        );

        $this->assertTrue(ShiprocketPickupInvalidStatus::matchesRejectedResult($result));
    }

    public function test_other_rejected_errors_do_not_match(): void
    {
        $result = new ShiprocketPickupResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'HTTP 400 — AWB not assigned',
        );

        $this->assertFalse(ShiprocketPickupInvalidStatus::matchesRejectedResult($result));
    }
}
