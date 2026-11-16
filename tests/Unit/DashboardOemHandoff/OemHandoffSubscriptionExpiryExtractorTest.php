<?php

namespace Tests\Unit\DashboardOemHandoff;

use App\Services\DashboardOemHandoff\OemHandoffSubscriptionExpiryExtractor;
use Tests\TestCase;

class OemHandoffSubscriptionExpiryExtractorTest extends TestCase
{
    private OemHandoffSubscriptionExpiryExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new OemHandoffSubscriptionExpiryExtractor;
    }

    public function test_mantra_uses_rd_expiry_date_only(): void
    {
        $date = $this->extractor->extract('mantra', [
            'rd_expiry_date' => '2026-06-01',
            'warranty_expiry_date' => '2027-01-15',
        ]);

        $this->assertSame('2026-06-01', $date);
    }

    public function test_morpho_uses_fields_expiry_date(): void
    {
        $date = $this->extractor->extract('morpho', [
            'fields' => [
                'expiry_date' => '2027-05-01',
                'whitelist_status' => 'whitelisted',
            ],
        ]);

        $this->assertSame('2027-05-01', $date);
    }

    public function test_returns_null_when_expiry_missing(): void
    {
        $this->assertNull($this->extractor->extract('mantra', ['rd_expiry_date' => null]));
        $this->assertNull($this->extractor->extract('morpho', ['fields' => []]));
    }
}
