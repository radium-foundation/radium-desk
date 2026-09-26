<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\CaMonthlyReportXmlCellEncoder;
use Tests\TestCase;

class CaMonthlyReportXmlCellEncoderTest extends TestCase
{
    public function test_inline_string_xml_escapes_markup_and_strips_control_characters(): void
    {
        $encoder = new CaMonthlyReportXmlCellEncoder;

        $xml = $encoder->inlineStringXml("Acme <beta> & \"quote\"\x00\x1F");

        $this->assertStringContainsString('Acme &lt;beta&gt; &amp;', $xml);
        $this->assertStringNotContainsString('<beta>', $xml);
        $this->assertStringNotContainsString("\x00", $xml);
        $this->assertStringNotContainsString("\x1F", $xml);
    }

    public function test_numeric_cell_value_rejects_identifier_like_strings(): void
    {
        $encoder = new CaMonthlyReportXmlCellEncoder;

        $this->assertSame('118.00', $encoder->numericCellValue('118.00'));
        $this->assertNull($encoder->numericCellValue('09AAJFV1437D1Z7'));
        $this->assertNull($encoder->numericCellValue('INV-0767292'));
    }

    public function test_sanitize_text_preserves_unicode_and_limits_length(): void
    {
        $encoder = new CaMonthlyReportXmlCellEncoder;

        $this->assertSame('इनायत कम्युनिकेशन्स', $encoder->sanitizeText('इनायत कम्युनिकेशन्स'));
        $this->assertSame(32767, strlen($encoder->sanitizeText(str_repeat('A', 40000))));
    }
}
