<?php

namespace Tests\Unit\Support;

use App\Support\LegacyOrderDisplay;
use PHPUnit\Framework\TestCase;

class LegacyOrderDisplayTest extends TestCase
{
    public function test_delivery_address_uses_checkout_pin_and_marks_profile_mismatch(): void
    {
        $formatted = LegacyOrderDisplay::formatDeliveryAddress([
            'line' => 'Sukanta Sarani R.K.Pally, Sonarpur',
            'district' => 'South 24 Parganas',
            'state' => 'West Bengal',
            'pincode' => '700150',
            'source' => 'order_checkout_snapshot',
            'pincode_profile_mismatch' => true,
        ]);

        $this->assertStringContainsString('Sukanta Sarani R.K.Pally, Sonarpur', (string) $formatted);
        $this->assertStringContainsString('PIN 700150', (string) $formatted);
        $this->assertStringContainsString('order checkout; customer profile PIN differs', (string) $formatted);
    }

    public function test_inr_amount_formats_whole_rupees_without_decimals(): void
    {
        $this->assertSame('₹2735', LegacyOrderDisplay::formatInrAmount('2735'));
    }
}
