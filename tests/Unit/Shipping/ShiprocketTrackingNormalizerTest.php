<?php

namespace Tests\Unit\Shipping;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\ShiprocketTrackNormalized;
use App\Services\Shipping\ShiprocketTrackingNormalizer;
use Tests\TestCase;

class ShiprocketTrackingNormalizerTest extends TestCase
{
    public function test_maps_only_verified_provider_strings(): void
    {
        $normalizer = new ShiprocketTrackingNormalizer;

        $this->assertSame(ShiprocketTrackNormalized::PickupQueued, $normalizer->normalize('12'));
        $this->assertSame(ShiprocketTrackNormalized::PickupQueued, $normalizer->normalize('Pickup Queue'));
        $this->assertSame(ShiprocketTrackNormalized::PickupQueued, $normalizer->normalize('already in pickup queue'));
        $this->assertSame(ShiprocketTrackNormalized::OutForPickup, $normalizer->normalize('19'));
        $this->assertSame(ShiprocketTrackNormalized::OutForPickup, $normalizer->normalize('Out for Pickup'));
        $this->assertSame(ShiprocketTrackNormalized::OutForPickup, $normalizer->normalize('out_for_pickup'));
        $this->assertSame(ShiprocketTrackNormalized::PickedUp, $normalizer->normalize('42'));
        $this->assertSame(ShiprocketTrackNormalized::PickedUp, $normalizer->normalize('PICKED UP'));
        $this->assertSame(ShiprocketTrackNormalized::InTransit, $normalizer->normalize('in_transit'));
        $this->assertSame(ShiprocketTrackNormalized::InTransit, $normalizer->normalize('In Transit'));
        $this->assertSame(ShiprocketTrackNormalized::Delivered, $normalizer->normalize('delivered'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize('7'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize('18'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize('some_unmapped_status'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize(''));
        $this->assertFalse($normalizer->normalize('12')->overridesReadyForPickup());
        $this->assertTrue($normalizer->normalize('19')->overridesReadyForPickup());
        $this->assertTrue($normalizer->normalize('42')->overridesReadyForPickup());
        $this->assertTrue($normalizer->normalize('in_transit')->overridesReadyForPickup());
        $this->assertTrue($normalizer->normalize('delivered')->overridesReadyForPickup());
        $this->assertSame(HardwareFulfilmentOperationalStage::OutForPickup, $normalizer->normalize('19')->operationalStage());
        $this->assertSame('Out for Pickup', $normalizer->normalize('19')->dashboardStatusLabel());
    }

    public function test_unknown_raw_status_can_use_verified_activity_text(): void
    {
        $normalizer = new ShiprocketTrackingNormalizer;

        $mapped = $normalizer->normalizeTrack('99', [
            [
                'current_status' => 'Out for Pickup',
                'activity' => 'Out for Pickup',
            ],
        ]);

        $this->assertSame(ShiprocketTrackNormalized::OutForPickup, $mapped);
        $this->assertSame(
            ShiprocketTrackNormalized::Unknown,
            $normalizer->normalizeTrack('99', [['activity' => 'Unknown scan']]),
        );
        $this->assertSame(
            ShiprocketTrackNormalized::OutForPickup,
            $normalizer->normalizeTrack('19', [['activity' => 'Picked up']]),
        );
    }
}
