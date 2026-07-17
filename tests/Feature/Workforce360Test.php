<?php

namespace Tests\Feature;

use App\Enums\TeamAvailabilityStatus;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Workforce360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_view_team_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createScheduledAgent('Tracked Agent');

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Team Workforce')
            ->assertSee('Coming in Sprint 3')
            ->assertSee('On Duty');
    }

    public function test_agent_can_view_my_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Self Agent');

        $this->actingAs($agent)
            ->get(route('my-workforce.index'))
            ->assertOk()
            ->assertSee('My Workforce')
            ->assertSee('Self Agent')
            ->assertSee('Today schedule');
    }

    public function test_agent_can_view_team_workforce_read_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Team Viewer');

        $this->actingAs($agent)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Team Workforce');
    }

    public function test_agent_cannot_view_other_member_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Agent One');
        $other = $this->createScheduledAgent('Agent Two');

        $this->actingAs($agent)
            ->get(route('workforce.show', $other))
            ->assertForbidden();
    }

    public function test_admin_can_view_individual_workforce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = $this->createScheduledAgent('Visible Agent');

        $this->actingAs($admin)
            ->get(route('workforce.show', $agent))
            ->assertOk()
            ->assertSee('Visible Agent')
            ->assertSee('Individual Workforce')
            ->assertSee('Presence');
    }

    public function test_show_redirects_to_my_workforce_for_self(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Redirect Agent');

        $this->actingAs($agent)
            ->get(route('workforce.show', $agent))
            ->assertRedirect(route('my-workforce.index'));
    }

    public function test_timeline_tab_shows_placeholder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Timeline Agent');

        $this->actingAs($agent)
            ->get(route('my-workforce.index', ['tab' => 'timeline']))
            ->assertOk()
            ->assertSee('Timeline Engine');
    }

    private function createScheduledAgent(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }
}
