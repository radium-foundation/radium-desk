<?php

namespace Tests\Unit\Reports\CaMonthly;

use App\Reports\CaMonthly\CaMonthlyReportGstinFormatStatusDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportGstinFormatStatusDisplayTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    public function test_not_present_when_gstin_missing(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => null]);

        $this->assertSame(
            CaMonthlyReportGstinFormatStatusDisplay::NOT_PRESENT,
            CaMonthlyReportGstinFormatStatusDisplay::forInvoice($invoice),
        );
    }

    public function test_format_valid_for_checksum_valid_gstin(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => '07AAICP1128M1Z9']);

        $this->assertSame(
            CaMonthlyReportGstinFormatStatusDisplay::FORMAT_VALID,
            CaMonthlyReportGstinFormatStatusDisplay::forInvoice($invoice),
        );
    }

    public function test_format_invalid_for_malformed_gstin(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => 'NOT-A-GSTIN']);

        $this->assertSame(
            CaMonthlyReportGstinFormatStatusDisplay::FORMAT_INVALID,
            CaMonthlyReportGstinFormatStatusDisplay::forInvoice($invoice),
        );
    }
}
