<?php

namespace Tests\Unit\Shipping;

use App\Enums\ShiprocketTrackNormalized;
use App\Services\Shipping\Data\ShiprocketTrackResult;
use App\Services\Shipping\ShiprocketTrackingNormalizer;
use Tests\TestCase;

class ShiprocketTrackingNormalizerTest extends TestCase
{
    public function test_status_id_19_normalizes_to_out_for_pickup(): void
    {
        $result = new ShiprocketTrackResult(
            provider: 'shiprocket',
            status: '19',
            activities: [[
                'current_status' => 'Out for Pickup',
                'current_status_id' => 19,
            ]],
            awb: '77191051976',
        );

        $normalized = ShiprocketTrackingNormalizer::normalize($result);

        $this->assertSame('Out for Pickup', $normalized['provider_track_status']);
        $this->assertSame(ShiprocketTrackNormalized::OutForPickup, $normalized['normalized']);
        $this->assertTrue(ShiprocketTrackingNormalizer::pickupAlreadyAdvanced($result));
    }

    public function test_status_id_3_and_pickup_generated_normalize_to_pickup_queued(): void
    {
        $result = new ShiprocketTrackResult(
            provider: 'shiprocket',
            status: '3',
            activities: [[
                'current_status' => 'Pickup Generated',
                'current_status_id' => 3,
            ]],
            awb: '284931180089754',
        );

        $normalized = ShiprocketTrackingNormalizer::normalize($result);

        $this->assertSame('Pickup Generated', $normalized['provider_track_status']);
        $this->assertSame(ShiprocketTrackNormalized::PickupQueued, $normalized['normalized']);
        $this->assertTrue(ShiprocketTrackingNormalizer::pickupAlreadyAdvanced($result));
    }

    public function test_pickup_advanced_on_shipment_uses_persisted_normalized_value(): void
    {
        $this->assertTrue(
            ShiprocketTrackingNormalizer::pickupAdvancedOnShipment('out_for_pickup', null)
        );
        $this->assertFalse(
            ShiprocketTrackingNormalizer::pickupAdvancedOnShipment('unknown', null)
        );
        $this->assertTrue(
            ShiprocketTrackingNormalizer::pickupAdvancedOnShipment(null, now())
        );
    }
}
