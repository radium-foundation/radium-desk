<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\Data\ShiprocketPickupResult;
use App\Services\Shipping\ShiprocketAlreadyQueuedPickup;
use Tests\TestCase;

class ShiprocketAlreadyQueuedPickupTest extends TestCase
{
    public function test_http_400_with_exact_or_punctuated_phrase_matches(): void
    {
        $this->assertTrue(ShiprocketAlreadyQueuedPickup::matchesHttp(400, [
            'message' => 'Already in Pickup Queue',
        ]));
        $this->assertTrue(ShiprocketAlreadyQueuedPickup::matchesHttp(400, [
            'message' => 'Already in Pickup Queue.',
        ]));
        $this->assertTrue(ShiprocketAlreadyQueuedPickup::matchesHttp(400, [
            'response' => ['data' => 'Already in Pickup Queue'],
        ]));
    }

    public function test_other_400_and_non_400_do_not_match(): void
    {
        $this->assertFalse(ShiprocketAlreadyQueuedPickup::matchesHttp(400, [
            'message' => 'AWB not assigned',
        ]));
        $this->assertFalse(ShiprocketAlreadyQueuedPickup::matchesHttp(422, [
            'message' => 'Already in Pickup Queue',
        ]));
        $this->assertFalse(ShiprocketAlreadyQueuedPickup::matchesHttp(200, [
            'message' => 'Already in Pickup Queue',
        ]));
    }

    public function test_rejected_wrapper_with_http_400_phrase_matches(): void
    {
        $rejected = new ShiprocketPickupResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'HTTP 400 — Already in Pickup Queue.',
        );
        $other = new ShiprocketPickupResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'HTTP 400 — AWB not assigned',
        );
        $retryable = new ShiprocketPickupResult(
            provider: 'shiprocket',
            status: 'failed',
            error: 'HTTP 400 — Already in Pickup Queue',
            retryable: true,
        );

        $this->assertTrue(ShiprocketAlreadyQueuedPickup::matchesRejectedResult($rejected));
        $this->assertFalse(ShiprocketAlreadyQueuedPickup::matchesRejectedResult($other));
        $this->assertFalse(ShiprocketAlreadyQueuedPickup::matchesRejectedResult($retryable));
    }
}
