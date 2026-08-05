<?php

namespace Tests\Unit\Dashboard;

use App\Models\PerformanceIntelligenceSnapshot;
use App\Support\Dashboard\TeamActivityBadgeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamActivityBadgeResolverTest extends TestCase
{
    use RefreshDatabase;

    private TeamActivityBadgeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(TeamActivityBadgeResolver::class);
        config([
            'team_activity_performance_badges.enabled' => true,
            'team_activity_performance_badges.max_badges' => 3,
            'performance_intelligence.commitment.outcome_floor' => 8,
            'team_activity_performance_badges.exceptional.composite_min' => 70,
        ]);
    }

    public function test_extra_contribution_requires_off_roster_and_outcome_floor(): void
    {
        $snapshot = $this->makeSnapshot(
            inputs: [
                'attendance_extra' => true,
                'attendance_on_leave' => false,
                'is_company_holiday' => false,
                'is_working_day' => false,
            ],
            breakdown: ['outcome_raw' => 8],
        );

        $this->assertTrue($this->resolver->qualifiesExtraContribution($snapshot));

        $idleLogin = $this->makeSnapshot(
            inputs: [
                'attendance_extra' => true,
                'is_working_day' => false,
            ],
            breakdown: ['outcome_raw' => 0],
        );

        $this->assertFalse($this->resolver->qualifiesExtraContribution($idleLogin));

        $workingDay = $this->makeSnapshot(
            inputs: [
                'attendance_extra' => false,
                'is_working_day' => true,
            ],
            breakdown: ['outcome_raw' => 40],
        );

        $this->assertFalse($this->resolver->qualifiesExtraContribution($workingDay));
    }

    public function test_exceptional_day_uses_configurable_composite_threshold(): void
    {
        config(['team_activity_performance_badges.exceptional.composite_min' => 75]);

        $below = $this->makeSnapshot(compositeScore: 74.9);
        $above = $this->makeSnapshot(compositeScore: 75.0);

        $this->assertFalse($this->resolver->qualifiesExceptionalDay($below));
        $this->assertTrue($this->resolver->qualifiesExceptionalDay($above));
    }

    public function test_team_helper_and_critical_work_are_architecture_only(): void
    {
        $snapshot = $this->makeSnapshot(
            inputs: ['assign_or_escalate_count' => 5],
            breakdown: ['outcome_raw' => 40],
            compositeScore: 90,
        );

        $this->assertFalse($this->resolver->qualifiesTeamHelper($snapshot));
        $this->assertFalse($this->resolver->qualifiesCriticalWork($snapshot));
    }

    public function test_resolve_limits_to_three_badges_by_priority(): void
    {
        config([
            'team_activity_performance_badges.priority' => [
                'exceptional_day',
                'extra_contribution',
                'critical_work',
                'team_helper',
            ],
            'team_activity_performance_badges.badges.team_helper.enabled' => true,
            'team_activity_performance_badges.badges.critical_work.enabled' => true,
        ]);

        $snapshot = $this->makeSnapshot(
            inputs: [
                'attendance_extra' => true,
                'is_working_day' => false,
            ],
            breakdown: ['outcome_raw' => 16],
            compositeScore: 80,
        );

        // Helper/critical still false even when enabled flags are on (no signal yet).
        $badges = $this->resolver->resolve($snapshot);

        $this->assertCount(2, $badges);
        $this->assertSame('exceptional_day', $badges[0]->key);
        $this->assertSame('extra_contribution', $badges[1]->key);
        $this->assertSame('🔥', $badges[0]->emoji);
        $this->assertSame('🌙', $badges[1]->emoji);
    }

    public function test_disabled_resolver_returns_empty(): void
    {
        config(['team_activity_performance_badges.enabled' => false]);

        $snapshot = $this->makeSnapshot(compositeScore: 99);

        $this->assertSame([], $this->resolver->resolve($snapshot));
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $breakdown
     */
    private function makeSnapshot(
        array $inputs = [],
        array $breakdown = [],
        float $compositeScore = 15,
    ): PerformanceIntelligenceSnapshot {
        return new PerformanceIntelligenceSnapshot([
            'user_id' => 1,
            'snapshot_date' => '2026-08-05',
            'version' => 'phase0.1',
            'outcome_score' => 50,
            'reach_score' => 50,
            'contribution_score' => 50,
            'commitment_score' => 50,
            'quality_score' => 100,
            'composite_score' => $compositeScore,
            'breakdown' => $breakdown,
            'inputs' => $inputs,
            'explanations' => [],
            'feature_flags' => [],
            'calculation_duration_ms' => 1,
            'calculated_at' => now(),
        ]);
    }
}
