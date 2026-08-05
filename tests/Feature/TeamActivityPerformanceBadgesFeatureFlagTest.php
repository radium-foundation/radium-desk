<?php

namespace Tests\Feature;

use App\Models\PerformanceIntelligenceSnapshot;
use App\Models\User;
use App\Services\Dashboard\TeamActivityPanelService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamActivityPerformanceBadgesFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-08-05 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_disabled_flag_skips_snapshot_query_and_badges(): void
    {
        config(['team_activity_performance_badges.enabled' => false]);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = User::factory()->create(['is_active' => true, 'name' => 'Badge Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        PerformanceIntelligenceSnapshot::query()->create([
            'user_id' => $agent->id,
            'snapshot_date' => '2026-08-05',
            'version' => 'phase0.1',
            'outcome_score' => 90,
            'reach_score' => 90,
            'contribution_score' => 90,
            'commitment_score' => 90,
            'quality_score' => 100,
            'composite_score' => 92,
            'breakdown' => ['outcome_raw' => 16],
            'inputs' => ['attendance_extra' => true, 'is_working_day' => false],
            'explanations' => [],
            'feature_flags' => [],
            'calculation_duration_ms' => 1,
            'calculated_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $panel = app(TeamActivityPanelService::class)->build();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('performance_intelligence_snapshots', $queries);

        $agentRow = collect($panel->agents)->firstWhere('id', $agent->id);
        $this->assertNotNull($agentRow);
        $this->assertSame([], $agentRow->performanceBadges);
    }

    public function test_enabled_flag_loads_badges_without_exposing_scores(): void
    {
        config([
            'team_activity_performance_badges.enabled' => true,
            'team_activity_performance_badges.exceptional.composite_min' => 70,
        ]);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = User::factory()->create(['is_active' => true, 'name' => 'Badge Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        PerformanceIntelligenceSnapshot::query()->create([
            'user_id' => $agent->id,
            'snapshot_date' => '2026-08-05',
            'version' => 'phase0.1',
            'outcome_score' => 90,
            'reach_score' => 90,
            'contribution_score' => 90,
            'commitment_score' => 90,
            'quality_score' => 100,
            'composite_score' => 92,
            'breakdown' => ['outcome_raw' => 16],
            'inputs' => [
                'attendance_extra' => true,
                'attendance_on_leave' => false,
                'is_company_holiday' => false,
                'is_working_day' => false,
            ],
            'explanations' => [],
            'feature_flags' => [],
            'calculation_duration_ms' => 1,
            'calculated_at' => now(),
        ]);

        $panel = app(TeamActivityPanelService::class)->build();
        $agentRow = collect($panel->agents)->firstWhere('id', $agent->id);

        $this->assertNotNull($agentRow);
        $this->assertCount(2, $agentRow->performanceBadges);
        $this->assertSame('exceptional_day', $agentRow->performanceBadges[0]['key']);
        $this->assertSame('extra_contribution', $agentRow->performanceBadges[1]['key']);
    }
}
