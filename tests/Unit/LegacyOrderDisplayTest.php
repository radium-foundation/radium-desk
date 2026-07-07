<?php

namespace Tests\Unit;

use App\Support\LegacyOrderDisplay;
use Tests\TestCase;

class LegacyOrderDisplayTest extends TestCase
{
    public function test_it_formats_amc_details_service_name_for_display(): void
    {
        $this->assertSame(
            '1 Year Standard',
            LegacyOrderDisplay::formatAmcDetails(['service_name' => '1 Year Standard']),
        );
    }

    public function test_it_formats_amc_details_json_string_for_display(): void
    {
        $this->assertSame(
            '1 Year Standard',
            LegacyOrderDisplay::formatAmcDetails('{"service_name":"1 Year Standard"}'),
        );
    }
}
