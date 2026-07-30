<?php

namespace Tests\Unit\Operations;

use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\TeamWorkScheduleService;
use App\Services\Operations\WorkCalendarService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WeeklyOffDaysResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_empty_weekly_off_days_uses_company_default_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Kolkata')); // Sunday

        $schedule = $this->makeSchedule([]);

        $calendar = app(WorkCalendarService::class);

        $this->assertSame([Carbon::SUNDAY], $calendar->resolvedWeeklyOffDays($schedule));
        $this->assertFalse($calendar->isWorkingDay($schedule, now()));
    }

    public function test_null_weekly_off_days_uses_company_default_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Kolkata')); // Sunday

        $schedule = $this->makeSchedule(null);

        $calendar = app(WorkCalendarService::class);

        $this->assertSame([Carbon::SUNDAY], $calendar->resolvedWeeklyOffDays($schedule));
        $this->assertFalse($calendar->isWorkingDay($schedule, now()));
    }

    public function test_string_zero_weekly_off_matches_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Kolkata')); // Sunday

        $schedule = $this->makeSchedule(['0']);

        $calendar = app(WorkCalendarService::class);

        $this->assertSame([0], $calendar->resolvedWeeklyOffDays($schedule));
        $this->assertFalse($calendar->isWorkingDay($schedule, now()));
    }

    public function test_upsert_never_persists_empty_weekly_off_days(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $schedule = app(TeamWorkScheduleService::class)->upsertForUser($agent, [
            'work_start_time' => '09:00',
            'work_end_time' => '18:00',
            'lunch_start_time' => '13:30',
            'lunch_end_time' => '14:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [],
        ]);

        $this->assertSame([Carbon::SUNDAY], $schedule->weekly_off_days);
        $this->assertNotSame([], $schedule->weekly_off_days);
    }

    public function test_upsert_without_weekly_off_days_key_uses_company_default(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $schedule = app(TeamWorkScheduleService::class)->upsertForUser($agent, [
            'work_start_time' => '09:00',
            'work_end_time' => '18:00',
            'lunch_start_time' => '13:30',
            'lunch_end_time' => '14:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
        ]);

        $this->assertSame([Carbon::SUNDAY], $schedule->weekly_off_days);
    }

    public function test_snapshot_resolves_empty_stored_offs_to_default(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [],
        ]);

        $snapshot = app(TeamWorkScheduleService::class)->snapshotFor($agent->fresh(['workSchedule']));

        $this->assertSame([Carbon::SUNDAY], $snapshot['weekly_off_days']);
    }

    public function test_explicit_non_sunday_offs_are_preserved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata')); // Monday

        $schedule = $this->makeSchedule([Carbon::MONDAY, Carbon::SATURDAY]);
        $calendar = app(WorkCalendarService::class);

        $this->assertSame([Carbon::MONDAY, Carbon::SATURDAY], $calendar->resolvedWeeklyOffDays($schedule));
        $this->assertFalse($calendar->isWorkingDay($schedule, now()));
        $this->assertTrue($calendar->isWorkingDay($schedule, Carbon::parse('2026-07-05 10:00:00', 'Asia/Kolkata')));
    }

    /**
     * @param  list<int|string>|null  $weeklyOffDays
     */
    private function makeSchedule(?array $weeklyOffDays): TeamMemberWorkSchedule
    {
        $agent = User::factory()->create(['is_active' => true]);

        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
        ]);
    }
}
