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

    public function test_grid_timeline_range_shows_date_once_for_same_day(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);

        $created = Carbon::parse('2026-07-19 10:40:00', 'Asia/Kolkata');
        $updated = Carbon::parse('2026-07-19 12:39:00', 'Asia/Kolkata');

        $this->assertSame(
            '19 Jul 10:40 → 12:39',
            AppDateFormatter::gridTimelineRange($created, $updated),
        );
    }

    public function test_grid_timeline_range_shows_both_dates_when_days_differ(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);

        $created = Carbon::parse('2026-07-18 22:15:00', 'Asia/Kolkata');
        $updated = Carbon::parse('2026-07-19 08:05:00', 'Asia/Kolkata');

        $this->assertSame(
            '18 Jul 22:15 → 19 Jul 08:05',
            AppDateFormatter::gridTimelineRange($created, $updated),
        );
    }
}
