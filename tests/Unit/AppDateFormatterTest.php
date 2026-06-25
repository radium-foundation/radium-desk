<?php

namespace Tests\Unit;

use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppDateFormatterTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_formats_datetime_in_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);

        $utc = Carbon::parse('2026-06-24 09:30:00', 'UTC');

        $this->assertSame('24 Jun 2026, 03:00 PM', AppDateFormatter::datetime($utc));
        $this->assertSame('24 Jun 2026, 15:00', AppDateFormatter::datetime24($utc));
        $this->assertSame('24 Jun 2026', AppDateFormatter::date($utc));
    }

    public function test_returns_null_for_missing_datetime(): void
    {
        $this->assertNull(AppDateFormatter::datetime(null));
    }
}
