<?php

namespace Tests\Unit\Operations;

use App\Models\AuditLog;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\TeamWorkScheduleService;
use App\Services\Operations\WorkCalendarService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EffectiveDatedWorkScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-31 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_schedule_for_returns_version_effective_on_date(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-07-20',
            'work_start_time' => '10:00:00',
            'work_end_time' => '00:00:00',
            'weekly_off_days' => [Carbon::SUNDAY],
            'expected_working_minutes' => 840,
        ]);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'effective_from' => '2026-07-21',
            'effective_to' => null,
            'work_start_time' => '10:00:00',
            'work_end_time' => '18:30:00',
            'lunch_start_time' => '13:00:00',
            'lunch_end_time' => '13:30:00',
            'weekly_off_days' => [Carbon::SUNDAY],
            'expected_working_minutes' => 480,
        ]);

        $calendar = app(WorkCalendarService::class);

        $july10 = $calendar->scheduleFor($user, Carbon::parse('2026-07-10'));
        $july25 = $calendar->scheduleFor($user, Carbon::parse('2026-07-25'));

        $this->assertNotNull($july10);
        $this->assertSame('00:00:00', (string) $july10->work_end_time);
        $this->assertNotNull($july25);
        $this->assertSame('18:30:00', (string) $july25->work_end_time);
    }

    public function test_supersede_closes_prior_version_and_writes_audit(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo('workforce-calendar.manage');
        $agent = User::factory()->create(['is_active' => true]);

        $service = app(TeamWorkScheduleService::class);

        $first = $service->upsertForUser($agent, [
            'work_start_time' => '09:00',
            'work_end_time' => '18:00',
            'lunch_start_time' => '13:30',
            'lunch_end_time' => '14:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
            'effective_from' => '2026-07-01',
        ], $admin->id);

        $this->assertNull($first->effective_to);
        $this->assertSame('2026-07-01', $first->effective_from->toDateString());

        $second = $service->upsertForUser($agent, [
            'work_start_time' => '10:00',
            'work_end_time' => '18:30',
            'lunch_start_time' => '13:00',
            'lunch_end_time' => '13:30',
            'short_break_count' => 0,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
            'effective_from' => '2026-08-01',
        ], $admin->id);

        $first->refresh();

        $this->assertSame('2026-07-31', $first->effective_to->toDateString());
        $this->assertNull($second->effective_to);
        $this->assertSame('2026-08-01', $second->effective_from->toDateString());
        $this->assertSame(2, TeamMemberWorkSchedule::query()->where('user_id', $agent->id)->count());

        $audit = AuditLog::query()
            ->where('event', TeamWorkScheduleService::AUDIT_EVENT_SUPERSEDED)
            ->where('auditable_id', $second->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('2026-08-01', $audit->new_values['effective_from'] ?? null);
    }

    public function test_creating_schedule_without_effective_from_defaults_to_today(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $schedule = TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $this->assertSame('2000-01-01', $schedule->effective_from->toDateString());
        $this->assertNull($schedule->effective_to);
    }
}
