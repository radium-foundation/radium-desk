<?php

namespace App\Support\Dashboard;

use App\Data\TeamActivityPerformanceBadge;
use App\Models\PerformanceIntelligenceSnapshot;

/**
 * Maps Performance Intelligence snapshots to Team Activity presentation badges.
 *
 * Consumes persisted snapshots only — never recalculates KPIs or scores for display.
 */
class TeamActivityBadgeResolver
{
    public function enabled(): bool
    {
        return (bool) config('team_activity_performance_badges.enabled', false);
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<int, PerformanceIntelligenceSnapshot|null>  $snapshotsByUserId
     * @return array<int, list<TeamActivityPerformanceBadge>>
     */
    public function resolveForUsers(array $userIds, array $snapshotsByUserId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $resolved = [];

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $badges = $this->resolve($snapshotsByUserId[$userId] ?? null);

            if ($badges !== []) {
                $resolved[$userId] = $badges;
            }
        }

        return $resolved;
    }

    /**
     * @return list<TeamActivityPerformanceBadge>
     */
    public function resolve(?PerformanceIntelligenceSnapshot $snapshot): array
    {
        if (! $this->enabled() || $snapshot === null) {
            return [];
        }

        $qualified = [];

        if ($this->qualifiesExtraContribution($snapshot)) {
            $qualified['extra_contribution'] = $this->makeBadge('extra_contribution');
        }

        if ($this->qualifiesTeamHelper($snapshot)) {
            $qualified['team_helper'] = $this->makeBadge('team_helper');
        }

        if ($this->qualifiesCriticalWork($snapshot)) {
            $qualified['critical_work'] = $this->makeBadge('critical_work');
        }

        if ($this->qualifiesExceptionalDay($snapshot)) {
            $qualified['exceptional_day'] = $this->makeBadge('exceptional_day');
        }

        return $this->limitByPriority($qualified);
    }

    /**
     * Meaningful off-roster work (PI Commitment evidence), not login duration.
     */
    public function qualifiesExtraContribution(PerformanceIntelligenceSnapshot $snapshot): bool
    {
        $inputs = $snapshot->inputs ?? [];
        $breakdown = $snapshot->breakdown ?? [];

        $offRoster = ! empty($inputs['attendance_extra'])
            || ! empty($inputs['attendance_on_leave'])
            || ! empty($inputs['is_company_holiday'])
            || empty($inputs['is_working_day']);

        if (! $offRoster) {
            return false;
        }

        $outcomeRaw = (int) ($breakdown['outcome_raw'] ?? 0);
        $floor = (int) (
            config('team_activity_performance_badges.badges.extra_contribution.outcome_raw_floor')
            ?? config('performance_intelligence.commitment.outcome_floor', 8)
        );

        return $outcomeRaw >= $floor;
    }

    /**
     * Reserved for shared contribution helper credit (not implemented in Phase 0).
     */
    public function qualifiesTeamHelper(PerformanceIntelligenceSnapshot $snapshot): bool
    {
        if (! (bool) config('team_activity_performance_badges.badges.team_helper.enabled', false)) {
            return false;
        }

        // Future: read helper_credit_count (or equivalent) from snapshot inputs.
        return false;
    }

    /**
     * Reserved until escalation/complexity is stored as a reliable snapshot signal.
     */
    public function qualifiesCriticalWork(PerformanceIntelligenceSnapshot $snapshot): bool
    {
        if (! (bool) config('team_activity_performance_badges.badges.critical_work.enabled', false)) {
            return false;
        }

        // Future: read escalation_count / complexity tier from snapshot inputs.
        return false;
    }

    public function qualifiesExceptionalDay(PerformanceIntelligenceSnapshot $snapshot): bool
    {
        $rules = config('team_activity_performance_badges.exceptional', []);
        $checks = [];

        $compositeMin = $rules['composite_min'] ?? null;
        if ($compositeMin !== null) {
            $checks[] = (float) $snapshot->composite_score >= (float) $compositeMin;
        }

        $outcomeMin = $rules['outcome_min'] ?? null;
        if ($outcomeMin !== null) {
            $checks[] = (int) $snapshot->outcome_score >= (int) $outcomeMin;
        }

        $qualityMin = $rules['quality_min'] ?? null;
        if ($qualityMin !== null) {
            $checks[] = (int) $snapshot->quality_score >= (int) $qualityMin;
        }

        if ($checks === []) {
            return false;
        }

        return ! empty($rules['require_all'])
            ? ! in_array(false, $checks, true)
            : in_array(true, $checks, true);
    }

    /**
     * @param  array<string, TeamActivityPerformanceBadge>  $qualified
     * @return list<TeamActivityPerformanceBadge>
     */
    private function limitByPriority(array $qualified): array
    {
        $priority = config('team_activity_performance_badges.priority', []);
        $max = max(1, (int) config('team_activity_performance_badges.max_badges', 3));
        $ordered = [];

        foreach ($priority as $key) {
            if (isset($qualified[$key])) {
                $ordered[] = $qualified[$key];
            }

            if (count($ordered) >= $max) {
                break;
            }
        }

        return $ordered;
    }

    private function makeBadge(string $key): TeamActivityPerformanceBadge
    {
        $definition = config("team_activity_performance_badges.badges.{$key}", []);

        return new TeamActivityPerformanceBadge(
            key: $key,
            emoji: (string) ($definition['emoji'] ?? ''),
            title: (string) ($definition['title'] ?? $key),
            tooltip: (string) ($definition['tooltip'] ?? ''),
        );
    }
}
