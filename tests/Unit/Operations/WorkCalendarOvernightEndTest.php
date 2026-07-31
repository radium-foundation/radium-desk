<?php

namespace Tests\Unit\Operations;

use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\WorkCalendarService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkCalendarOvernightEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_overnight_expected_end_for_work_date_start_of_day_is_next_midnight(): void
    {
        $schedule = $this->overnightSchedule('10:00:00', '00:00:00');
        $calendar = app(WorkCalendarService::class);
        $workDate = Carbon::parse('2026-07-29', 'Asia/Kolkata')->startOfDay();

        $expectedEnd = $calendar->expectedWorkEndAt($schedule, $workDate);

        $this->assertSame(
            '2026-07-30 00:00:00',
            $expectedEnd->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
        $this->assertNotSame(
            '2026-07-29 00:00:00',
            $expectedEnd->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
    }

    public function test_overnight_expected_end_at_login_during_shift_matches_next_midnight(): void
    {
        $schedule = $this->overnightSchedule('10:00:00', '00:00:00');
        $calendar = app(WorkCalendarService::class);
        $loginAt = Carbon::parse('2026-07-29 23:45:10', 'Asia/Kolkata');

        $expectedEnd = $calendar->expectedWorkEndAt($schedule, $loginAt);

        $this->assertSame(
            '2026-07-30 00:00:00',
            $expectedEnd->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
    }

    public function test_overnight_ending_at_six_am_resolves_next_morning_from_shift_start(): void
    {
        $schedule = $this->overnightSchedule('22:00:00', '06:00:00');
        $calendar = app(WorkCalendarService::class);
        $workDate = Carbon::parse('2026-07-06', 'Asia/Kolkata')->startOfDay();
        $shiftStart = $calendar->expectedWorkStartAt($schedule, $workDate);

        $expectedEnd = $calendar->expectedWorkEndAt($schedule, $shiftStart);

        $this->assertSame(
            '2026-07-07 06:00:00',
            $expectedEnd->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
    }

    public function test_day_shift_expected_end_unchanged(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $schedule = TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $expectedEnd = app(WorkCalendarService::class)->expectedWorkEndAt(
            $schedule,
            Carbon::parse('2026-07-06', 'Asia/Kolkata')->startOfDay(),
        );

        $this->assertSame(
            '2026-07-06 18:00:00',
            $expectedEnd->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
    }

    private function overnightSchedule(string $start, string $end): TeamMemberWorkSchedule
    {
        $user = User::factory()->create(['is_active' => true]);

        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => $start,
            'work_end_time' => $end,
            'lunch_start_time' => null,
            'lunch_end_time' => null,
            'short_break_count' => 0,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
    }
}
