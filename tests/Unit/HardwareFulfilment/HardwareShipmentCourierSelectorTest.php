<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareShipmentCourierSelector;
use Tests\TestCase;

class HardwareShipmentCourierSelectorTest extends TestCase
{
    public function test_never_returns_a_rejected_courier_even_when_recommended(): void
    {
        $selector = new HardwareShipmentCourierSelector;
        $options = [
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
        ];

        $chosen = $selector->choose(
            $options,
            rejectedIds: ['15084'],
            recommendedId: '15084',
            anchor: $options[0],
            allowUnrankedEligible: true,
        );

        $this->assertSame('15106', $chosen['courier_id'] ?? null);
    }

    public function test_honors_configured_preferred_courier_when_serviceable(): void
    {
        config(['shipping.preferred_courier_ids' => ['15084']]);
        $selector = new HardwareShipmentCourierSelector;
        $options = [
            ['courier_id' => '48', 'courier_name' => 'Ekart Logistics Air', 'mode' => '1', 'provider_recommended' => true],
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
        ];

        $chosen = $selector->choose(
            $options,
            recommendedId: '48',
            allowUnrankedEligible: false,
        );

        $this->assertSame('15084', $chosen['courier_id'] ?? null);
    }

    public function test_after_rejection_keeps_anchor_mode_instead_of_recommended_air(): void
    {
        config(['shipping.preferred_courier_ids' => ['15084']]);
        $selector = new HardwareShipmentCourierSelector;
        $options = [
            ['courier_id' => '48', 'courier_name' => 'Ekart Logistics Air', 'mode' => '1', 'provider_recommended' => true],
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
        ];

        $chosen = $selector->choose(
            $options,
            rejectedIds: ['15084'],
            recommendedId: '48',
            anchor: $options[1],
            allowUnrankedEligible: true,
        );

        $this->assertSame('15106', $chosen['courier_id'] ?? null);
        $this->assertNotSame('48', $chosen['courier_id'] ?? null);
    }

    public function test_fails_closed_when_every_listed_courier_was_rejected(): void
    {
        $selector = new HardwareShipmentCourierSelector;
        $chosen = $selector->choose(
            [['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0']],
            rejectedIds: ['15084'],
            recommendedId: '15084',
            allowUnrankedEligible: true,
        );

        $this->assertNull($chosen);
    }

    public function test_does_not_invent_a_preferred_id_when_config_is_empty(): void
    {
        config(['shipping.preferred_courier_ids' => []]);
        $selector = new HardwareShipmentCourierSelector;

        $this->assertSame([], $selector->preferredCourierIds());

        $chosen = $selector->choose(
            [
                ['courier_id' => '48', 'courier_name' => 'Ekart Logistics Air', 'mode' => '1'],
                ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
            ],
            recommendedId: '48',
            allowUnrankedEligible: false,
        );

        $this->assertSame('48', $chosen['courier_id'] ?? null);
    }
}
