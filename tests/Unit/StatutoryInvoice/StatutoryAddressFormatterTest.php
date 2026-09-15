<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\StatutoryAddressFormatter;
use PHPUnit\Framework\TestCase;

class StatutoryAddressFormatterTest extends TestCase
{
    public function test_short_address_fits_addr1_only(): void
    {
        $formatted = (new StatutoryAddressFormatter)->format('1 Test Street, Delhi');

        $this->assertSame('1 Test Street, Delhi', $formatted['addr1']);
        $this->assertSame('', $formatted['addr2']);
        $this->assertFalse($formatted['exceeds_limit']);
    }

    public function test_structured_199_char_address_splits_without_truncation(): void
    {
        $line1 = str_repeat('A', 98).',';
        $line2 = str_repeat('B', 99);
        $formatted = (new StatutoryAddressFormatter)->format(null, [
            'line1' => $line1,
            'line2' => $line2,
            'city' => 'Ramanagara',
            'state' => 'Karnataka',
            'pincode' => '562112',
        ]);

        $this->assertFalse($formatted['exceeds_limit']);
        $this->assertLessThanOrEqual(100, strlen($formatted['addr1']));
        $this->assertLessThanOrEqual(100, strlen($formatted['addr2']));
        $this->assertLessThanOrEqual(200, strlen($formatted['combined']));
        $this->assertSame(199, strlen(trim(rtrim($line1).' '.$line2)));
    }

    public function test_201_char_address_exceeds_limit(): void
    {
        $text = str_repeat('A', 201);
        $formatted = (new StatutoryAddressFormatter)->format($text);

        $this->assertTrue($formatted['exceeds_limit']);
    }
}
