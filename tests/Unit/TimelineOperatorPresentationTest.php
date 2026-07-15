<?php

namespace Tests\Unit;

use App\Support\AppDateFormatter;
use App\Support\Timeline\TimelineGroupResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimelineOperatorPresentationTest extends TestCase
{
    public function test_operator_relative_time_formats_recent_and_calendar_labels(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:45:00', 'Asia/Kolkata'));

        $this->assertSame('Just now', AppDateFormatter::timelineOperatorRelative(now()));
        $this->assertSame('2 min ago', AppDateFormatter::timelineOperatorRelative(now()->subMinutes(2)));
        $this->assertSame('Today • 08:42 AM', AppDateFormatter::timelineOperatorRelative(
            Carbon::parse('2026-07-15 08:42:00', 'Asia/Kolkata'),
        ));
        $this->assertSame('Yesterday • 06:15 PM', AppDateFormatter::timelineOperatorRelative(
            Carbon::parse('2026-07-14 18:15:00', 'Asia/Kolkata'),
        ));
        $this->assertSame('10 Jul • 04:30 PM', AppDateFormatter::timelineOperatorRelative(
            Carbon::parse('2026-07-10 16:30:00', 'Asia/Kolkata'),
        ));
    }

    public function test_group_resolver_labels_weekday_and_last_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata')); // Wednesday

        $this->assertSame('Today', TimelineGroupResolver::resolve(now())['label']);
        $this->assertSame('Yesterday', TimelineGroupResolver::resolve(now()->subDay())['label']);
        $this->assertSame('Monday', TimelineGroupResolver::resolve(
            Carbon::parse('2026-07-13 09:00:00', 'Asia/Kolkata'),
        )['label']);
        $this->assertSame('Last Week', TimelineGroupResolver::resolve(
            Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'),
        )['label']);
        $this->assertSame('Earlier', TimelineGroupResolver::resolve(
            Carbon::parse('2026-06-20 09:00:00', 'Asia/Kolkata'),
        )['label']);
    }
}
