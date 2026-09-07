<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareIssuer;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareIssuerTest extends TestCase
{
    private HardwareIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureLocationSellerIdentity();
        $this->issuer = app(HardwareIssuer::class);
    }

    public function test_delhi_b2c_uses_inv_671_location(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::DELHI_B2C,
            $this->issuer->require('DELHI-RETAIL', null),
        );
    }

    public function test_delhi_b2b_uses_inv_07671_location(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::DELHI,
            $this->issuer->require('DELHI-RETAIL', '07AAAAA0000A1Z5'),
        );
    }

    public function test_mumbai_b2c_and_b2b_share_mumbai_location(): void
    {
        $this->assertSame(StatutoryLocationSeries::MUMBAI, $this->issuer->require('MUMBAI', null));
        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->require('MUMBAI', '27AAAAA0000A1Z5'),
        );
    }

    public function test_invalid_gstin_fails_closed(): void
    {
        $this->expectException(ValidationException::class);
        $this->issuer->require('DELHI-RETAIL', 'INVALID');
    }

    public function test_unmapped_branch_fails_closed(): void
    {
        $this->expectException(ValidationException::class);
        $this->issuer->require('CHENNAI', null);
    }
}
