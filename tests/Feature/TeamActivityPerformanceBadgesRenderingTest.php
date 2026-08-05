<?php

namespace Tests\Feature;

use App\Enums\TeamAvailabilityStatus;
use App\Models\PerformanceIntelligenceSnapshot;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Dashboard\TeamActivityPanelService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityPerformanceBadgesRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'dashboard-team-activity.enabled' => true,
            'team_activity_performance_badges.enabled' => true,
            'team_activity_performance_badges.exceptional.composite_min' => 70,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-05 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_renders_badges_and_tooltips_without_scores(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = $this->createAgent('Extra Day Agent');

        PerformanceIntelligenceSnapshot::query()->create([
            'user_id' => $agent->id,
            'snapshot_date' => '2026-08-05',
            'version' => 'phase0.1',
            'outcome_score' => 80,
            'reach_score' => 80,
            'contribution_score' => 80,
            'commitment_score' => 80,
            'quality_score' => 100,
            'composite_score' => 82,
            'breakdown' => ['outcome_raw' => 16],
            'inputs' => [
                'attendance_extra' => true,
                'is_working_day' => false,
            ],
            'explanations' => [],
            'feature_flags' => [],
            'calculation_duration_ms' => 1,
            'calculated_at' => now(),
        ]);

        $html = app(TeamActivityPanelService::class)->render(
            app(TeamActivityPanelService::class)->build(),
        );

        $this->assertStringContainsString('team-activity-performance-badge', $html);
        $this->assertStringContainsString('team-activity-performance-badge__icon', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('m12 3-1.912 5.813', $html);
        $this->assertStringNotContainsString('🌙', $html);
        $this->assertStringNotContainsString('🔥', $html);
        $this->assertStringContainsString('data-performance-badge="extra_contribution"', $html);
        $this->assertStringContainsString('data-performance-badge="exceptional_day"', $html);
        $this->assertStringContainsString('aria-label="Extra Contribution"', $html);
        $this->assertStringContainsString('aria-label="Exceptional Day"', $html);
        $this->assertStringContainsString('title="Extra Contribution', $html);
        $this->assertStringContainsString('Meaningful work completed outside scheduled hours.', $html);
        $this->assertStringContainsString('Operational recognition only.', $html);
        $this->assertStringContainsString('Extra Contribution', $html);
        $this->assertStringNotContainsString('composite_score', $html);
        $this->assertStringNotContainsString('outcome_score', $html);
        $this->assertStringNotContainsString('RPE Index', $html);
        $this->assertStringNotContainsString('Performance Intelligence', $html);
    }

    public function test_agent_without_permission_still_follows_team_activity_access(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('team-activity-performance-badge', false);
    }

    private function createAgent(string $name): User
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

        return $user;
    }
}
