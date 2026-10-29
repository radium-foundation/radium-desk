<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Tests\TestCase;

class StatutoryBillingStructuredPdfLocalityTest extends TestCase
{
    public function test_pdf_locality_line_when_street_only_in_billing_address(): void
    {
        $structured = [
            'line1' => 'Gat No. 148/3 to 148/7, Chakan Talegaon Road, Mahalunge',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '410501',
        ];

        $line = StatutoryBillingStructured::pdfLocalityLine($structured, $structured['line1']);

        $this->assertSame('Pune, Maharashtra 410501', $line);
    }

    public function test_pdf_locality_line_omits_components_already_in_free_text(): void
    {
        $billing = '312 dhamankar plaza, Bhiwandi, Thane, Maharashtra, 421305';
        $structured = [
            'line1' => '312 dhamankar plaza',
            'city' => 'Bhiwandi',
            'state' => 'Maharashtra',
            'pincode' => '421305',
        ];

        $this->assertNull(StatutoryBillingStructured::pdfLocalityLine($structured, $billing));
    }

    public function test_pdf_locality_line_returns_null_without_structured_parts(): void
    {
        $this->assertNull(StatutoryBillingStructured::pdfLocalityLine(null, 'Some address'));
    }
}
