<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\StatutoryInvoiceNumberFormatter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryInvoiceNumberFormatterTest extends TestCase
{
    public function test_formats_configured_series_and_padded_sequence(): void
    {
        $number = (new StatutoryInvoiceNumberFormatter)->format(
            '{series}-{seq:5}',
            'TEST',
            3,
            null,
            null,
        );

        $this->assertSame('TEST-00003', $number);
    }

    public function test_refuses_fy_token_when_financial_year_is_unset(): void
    {
        $this->expectException(ValidationException::class);

        (new StatutoryInvoiceNumberFormatter)->format(
            '{series}/{fy}/{seq}',
            'TEST',
            1,
            null,
            null,
        );
    }
}
