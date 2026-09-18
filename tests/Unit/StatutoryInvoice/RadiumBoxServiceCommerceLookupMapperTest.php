<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\RadiumBoxServiceCommerceLookupMapper;
use Tests\TestCase;

class RadiumBoxServiceCommerceLookupMapperTest extends TestCase
{
    private RadiumBoxServiceCommerceLookupMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new RadiumBoxServiceCommerceLookupMapper;
    }

    public function test_invalid_gstin_is_mapped_to_null(): void
    {
        $lookup = $this->mapper->map([
            'status' => 200,
            'data' => [
                'service_commerce' => [
                    'rdorderid' => 'RB94',
                    'billing_state' => 'Uttar Pradesh',
                    'place_of_supply_state' => 'Uttar Pradesh',
                    'billing_address' => 'Lucknow',
                    'gst_no' => 'na',
                    'taxable_value' => 505.94,
                    'tax_total' => 91.06,
                    'line_total' => 597.0,
                    'gst_percentage' => 18.0,
                    'catalog_hsn_sac' => '998314',
                    'service_description' => 'Service line',
                    'ordered_at' => '2026-09-12 10:00:00',
                ],
            ],
        ], 'RB94');

        $this->assertNull($lookup->buyerGstin);
    }

    public function test_duration_fields_are_mapped_when_present(): void
    {
        $lookup = $this->mapper->map([
            'status' => 200,
            'data' => [
                'service_commerce' => [
                    'rdorderid' => 'RB115',
                    'billing_state' => 'Bihar',
                    'place_of_supply_state' => 'Bihar',
                    'billing_address' => 'Patna',
                    'base_taxable_value' => 507.63,
                    'duration' => 'express',
                    'duration_price' => 100.0,
                    'taxable_value' => 607.63,
                    'tax_total' => 109.37,
                    'line_total' => 717.0,
                    'gst_percentage' => 18.0,
                    'catalog_hsn_sac' => '998314',
                    'service_description' => 'Service line',
                    'ordered_at' => '2026-09-12 10:00:00',
                ],
            ],
        ], 'RB115');

        $this->assertSame('express', $lookup->durationType);
        $this->assertSame(100.0, $lookup->durationPrice);
        $this->assertSame(507.63, $lookup->baseTaxableValue);
        $this->assertSame(607.63, $lookup->taxableValue);
    }
}
