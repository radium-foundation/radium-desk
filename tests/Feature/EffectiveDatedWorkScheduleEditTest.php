<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\TeamWorkScheduleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EffectiveDatedWorkScheduleEditTest extends TestCase
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

    public function test_admin_can_save_schedule_with_tomorrow_effective_from(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        $admin->givePermissionTo('workforce-calendar.manage');

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
            'expected_working_minutes' => 490,
        ]);

        $this->actingAs($admin)
            ->put(route('users.work-schedule.update', $agent), [
                'work_start_time' => '10:00',
                'work_end_time' => '18:30',
                'lunch_start_time' => '13:00',
                'lunch_end_time' => '13:30',
                'short_break_count' => 0,
                'short_break_minutes' => 10,
                'weekly_off_days' => [0],
                'effective_from_preset' => 'tomorrow',
            ])
            ->assertRedirect(route('users.edit', $agent));

        $versions = TeamMemberWorkSchedule::query()
            ->where('user_id', $agent->id)
            ->orderBy('effective_from')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame('2026-07-31', $versions[0]->effective_to->toDateString());
        $this->assertSame('2026-08-01', $versions[1]->effective_from->toDateString());
        $this->assertNull($versions[1]->effective_to);
        $this->assertSame('10:00:00', (string) $versions[1]->work_start_time);

        $this->assertTrue(
            AuditLog::query()
                ->where('event', TeamWorkScheduleService::AUDIT_EVENT_SUPERSEDED)
                ->where('user_id', $admin->id)
                ->exists(),
        );
    }
}
