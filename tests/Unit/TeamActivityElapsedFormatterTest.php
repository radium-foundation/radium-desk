<?php

namespace Tests\Unit;

use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityElapsedFormatterTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_formats_seconds_minutes_and_hours_without_ago_suffix(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00', 'Asia/Kolkata'));

        $this->assertSame('45 sec', AppDateFormatter::teamActivityElapsed(now()->subSeconds(45)));
        $this->assertSame('2 min', AppDateFormatter::teamActivityElapsed(now()->subMinutes(2)));
        $this->assertSame('18 min', AppDateFormatter::teamActivityElapsed(now()->subMinutes(18)));
        $this->assertSame('1 hr', AppDateFormatter::teamActivityElapsed(now()->subMinutes(75)));
        $this->assertStringNotContainsString('ago', AppDateFormatter::teamActivityElapsed(now()->subMinutes(5)) ?? '');
    }
}
