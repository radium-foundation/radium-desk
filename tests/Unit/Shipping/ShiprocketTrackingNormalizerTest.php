<?php

namespace Tests\Unit\Shipping;

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
        $this->assertSame(ShiprocketTrackNormalized::InTransit, $normalizer->normalize('in_transit'));
        $this->assertSame(ShiprocketTrackNormalized::InTransit, $normalizer->normalize('In Transit'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize('delivered'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize('some_unmapped_status'));
        $this->assertSame(ShiprocketTrackNormalized::Unknown, $normalizer->normalize(''));
        $this->assertFalse($normalizer->normalize('12')->overridesReadyForPickup());
        $this->assertTrue($normalizer->normalize('in_transit')->overridesReadyForPickup());
    }
}
