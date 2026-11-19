<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\RadiumBoxServiceCommerceSpokeOverlay;
use Tests\TestCase;

class RadiumBoxServiceCommerceSpokeOverlayTest extends TestCase
{
    private RadiumBoxServiceCommerceSpokeOverlay $overlay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overlay = new RadiumBoxServiceCommerceSpokeOverlay;
    }

    public function test_synthesizes_rb1040_shaped_payload_without_service_commerce_block(): void
    {
        $commerce = $this->overlay->synthesize([
            'snapshot' => [
                'address' => '12 Fixture Lane, Lucknow',
                'pincode' => '226001',
                'serial_number' => 'MFS110-SERIAL',
                'rd_service' => '3 Year Unlimited',
                'product' => 'MFS 110',
                'order_date' => '2026-10-01 12:00:00',
            ],
            'rd_order' => [
                'rdorderid' => 'RB1040',
                'state' => 'Uttar Pradesh',
                'district' => 'Lucknow',
                'amount' => 744.92,
                'tax_amount' => 134.08,
                'paid_amount' => 879.00,
                'product_name' => 'MFS 110',
                'rd_service_name' => '3 Year Unlimited',
                'serial_no' => 'MFS110-SERIAL',
                'duration' => '3 Year Unlimited',
                'created_at' => '2026-10-01 12:00:00',
            ],
        ], 'RB1040');

        $this->assertNotNull($commerce);
        $this->assertSame('RB1040', $commerce['rdorderid']);
        $this->assertSame('Uttar Pradesh', $commerce['billing_state']);
        $this->assertSame(744.92, $commerce['taxable_value']);
        $this->assertSame(134.08, $commerce['tax_total']);
        $this->assertSame(879.0, $commerce['line_total']);
        $this->assertSame(18.0, $commerce['gst_percentage']);
        $this->assertSame('998314', $commerce['catalog_hsn_sac']);
    }

    public function test_returns_null_when_statutory_amounts_missing(): void
    {
        $this->assertNull($this->overlay->synthesize([
            'snapshot' => ['address' => 'Addr', 'serial_number' => '1', 'rd_service' => '1 Year'],
            'rd_order' => [
                'rdorderid' => 'RB1041',
                'state' => 'Uttar Pradesh',
                'paid_amount' => 879,
            ],
        ], 'RB1041'));
    }
}