<?php

namespace Tests\Unit;

use App\Enums\SupportAppointmentTimeSlot;
use App\Services\SupportScheduleAvailabilityService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SupportScheduleAvailabilityServiceTest extends TestCase
{
    private SupportScheduleAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SupportScheduleAvailabilityService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sunday_is_not_bookable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Kolkata'));

        $this->assertFalse($this->service->isDateBookable('2026-07-05'));
        $this->assertSame([], $this->service->availableTimeSlots('2026-07-05'));
        $this->assertStringContainsString(
            'Sundays',
            (string) $this->service->dateUnavailableMessage('2026-07-05'),
        );
    }

    public function test_same_day_morning_unavailable_after_eleven_am(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));

        $this->assertFalse($this->service->isTimeSlotAvailable(
            '2026-07-06',
            SupportAppointmentTimeSlot::Morning,
        ));
        $this->assertTrue($this->service->isTimeSlotAvailable(
            '2026-07-06',
            SupportAppointmentTimeSlot::Afternoon,
        ));
    }

    public function test_same_day_afternoon_unavailable_after_two_pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 14:00:00', 'Asia/Kolkata'));

        $this->assertFalse($this->service->isTimeSlotAvailable(
            '2026-07-06',
            SupportAppointmentTimeSlot::Afternoon,
        ));
        $this->assertTrue($this->service->isTimeSlotAvailable(
            '2026-07-06',
            SupportAppointmentTimeSlot::Evening,
        ));
    }

    public function test_same_day_evening_unavailable_after_five_pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 17:00:00', 'Asia/Kolkata'));

        $this->assertFalse($this->service->isTimeSlotAvailable(
            '2026-07-06',
            SupportAppointmentTimeSlot::Evening,
        ));
        $this->assertSame([], $this->service->availableTimeSlots('2026-07-06'));
        $this->assertFalse($this->service->isDateBookable('2026-07-06'));
    }

    public function test_future_weekday_date_has_all_slots_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->assertTrue($this->service->isDateBookable('2026-07-07'));
        $this->assertCount(3, $this->service->availableTimeSlots('2026-07-07'));
    }
}
