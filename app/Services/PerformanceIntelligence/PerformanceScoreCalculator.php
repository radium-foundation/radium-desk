<?php

namespace App\Services\PerformanceIntelligence;

use App\Data\PerformanceIntelligence\PerformanceDayInputs;
use App\Data\PerformanceIntelligence\PerformanceScoreResult;

/**
 * Transparent Phase 0 pillar math.
 *
 * Every score ships with human-readable explanation lines (no black box).
 * Weights/points/caps come from config/performance_intelligence.php.
 */
class PerformanceScoreCalculator
{
    public function calculate(PerformanceDayInputs $inputs, int $durationMs = 0): PerformanceScoreResult
    {
        $points = config('performance_intelligence.points', []);
        $caps = config('performance_intelligence.caps', []);
        $normalize = config('performance_intelligence.normalize', []);
        $commitmentCfg = config('performance_intelligence.commitment', []);
        $qualityCfg = config('performance_intelligence.quality', []);
        $weights = config('performance_intelligence.weights', []);

        $explanations = [
            'outcome' => [],
            'reach' => [],
            'contribution' => [],
            'commitment' => [],
            'quality' => [],
            'composite' => [],
        ];

        // --- Outcome raw ---
        $resolvedPts = $inputs->resolvedCount * (int) ($points['resolved'] ?? 8);
        $closedAfterResolve = min($inputs->closedCount, $inputs->resolvedCount);
        $closedOnly = max(0, $inputs->closedCount - $closedAfterResolve);
        $closedPts = $closedAfterResolve * (int) ($points['closed_after_resolve_same_day'] ?? 2)
            + $closedOnly * (int) ($points['closed'] ?? 5);
        $refundPts = $inputs->refundDecisionCount * (int) ($points['refund_decision'] ?? 4);
        $outcomeRaw = $resolvedPts + $closedPts + $refundPts;

        $explanations['outcome'][] = sprintf(
            'Resolved %d × %d = %d',
            $inputs->resolvedCount,
            (int) ($points['resolved'] ?? 8),
            $resolvedPts,
        );
        $explanations['outcome'][] = sprintf(
            'Closed %d (after-resolve %d × %d, close-only %d × %d) = %d',
            $inputs->closedCount,
            $closedAfterResolve,
            (int) ($points['closed_after_resolve_same_day'] ?? 2),
            $closedOnly,
            (int) ($points['closed'] ?? 5),
            $closedPts,
        );
        $explanations['outcome'][] = sprintf(
            'Refund decisions %d × %d = %d',
            $inputs->refundDecisionCount,
            (int) ($points['refund_decision'] ?? 4),
            $refundPts,
        );
        $explanations['outcome'][] = "Outcome raw points = {$outcomeRaw}";

        // --- Reach ---
        $reachRaw = $inputs->casesWorked;
        $substance = $outcomeRaw > 0
            || ($inputs->touchBreakdown['whatsapp'] ?? 0) > 0
            || ($inputs->touchBreakdown['emails'] ?? 0) > 0
            || $inputs->answeredCallCount > 0;
        if ($inputs->casesWorked > 0 && ! $substance) {
            $reachRaw = 0;
            $explanations['reach'][] = 'Cases Worked > 0 but no Outcome/Interaction substance — Reach set to 0 (touch-and-run guard)';
        } else {
            $explanations['reach'][] = "Cases Worked (distinct Reference Nos.) = {$inputs->casesWorked}";
        }

        // --- Contribution ---
        $wa = (int) ($inputs->touchBreakdown['whatsapp'] ?? 0);
        $emails = (int) ($inputs->touchBreakdown['emails'] ?? 0);
        $remarks = min(
            (int) ($inputs->touchBreakdown['remarks'] ?? 0),
            (int) ($caps['remarks_total'] ?? 30),
        );
        $statusUpdates = (int) ($inputs->touchBreakdown['status_updates'] ?? 0);
        // Intermediate status approx: total status flips minus resolve/close/reopen
        $intermediateStatus = max(0, $statusUpdates - $inputs->resolvedCount - $inputs->closedCount - $inputs->reopenCount);
        $intermediateStatus = min($intermediateStatus, (int) ($caps['status_intermediate_total'] ?? 40));
        $assign = min($inputs->assignOrEscalateCount, max(1, $inputs->casesWorked) * (int) ($caps['assign_per_case'] ?? 1));

        $contribRaw =
            $inputs->answeredCallCount * (int) ($points['answered_call'] ?? 4)
            + $wa * (int) ($points['manual_whatsapp'] ?? 3)
            + $emails * (int) ($points['human_email'] ?? 3)
            + $remarks * (int) ($points['manual_remark'] ?? 1)
            + $intermediateStatus * (int) ($points['status_intermediate'] ?? 1)
            + $assign * (int) ($points['assign_or_escalate'] ?? 1);

        $explanations['contribution'][] = sprintf(
            'Answered calls %d × %d',
            $inputs->answeredCallCount,
            (int) ($points['answered_call'] ?? 4),
        );
        $explanations['contribution'][] = sprintf('Manual WhatsApp %d × %d', $wa, (int) ($points['manual_whatsapp'] ?? 3));
        $explanations['contribution'][] = sprintf('Human email events %d × %d', $emails, (int) ($points['human_email'] ?? 3));
        $explanations['contribution'][] = sprintf(
            'Manual remarks %d (capped at %d) × %d — deletes ignored',
            (int) ($inputs->touchBreakdown['remarks'] ?? 0),
            (int) ($caps['remarks_total'] ?? 30),
            (int) ($points['manual_remark'] ?? 1),
        );
        $explanations['contribution'][] = sprintf(
            'Intermediate status flips ~%d (capped) × %d',
            $intermediateStatus,
            (int) ($points['status_intermediate'] ?? 1),
        );
        $explanations['contribution'][] = sprintf('Assign/escalate %d (capped) × %d', $assign, (int) ($points['assign_or_escalate'] ?? 1));
        $explanations['contribution'][] = "Contribution raw points = {$contribRaw}";
        $explanations['contribution'][] = 'Customer Touches diagnostic (not scored): '.$inputs->customerTouches;

        // --- Commitment ---
        $commitmentRaw = 0;
        $outcomeFloor = (int) ($commitmentCfg['outcome_floor'] ?? 8);
        $offRoster = $inputs->attendanceExtra || $inputs->attendanceOnLeave || $inputs->isCompanyHoliday || ! $inputs->isWorkingDay;

        if ($offRoster && $outcomeRaw >= $outcomeFloor) {
            if ($inputs->attendanceOnLeave) {
                $commitmentRaw = (int) ($commitmentCfg['leave_points'] ?? 16);
                $explanations['commitment'][] = "On leave with Outcome raw ≥ {$outcomeFloor} → +{$commitmentRaw}";
            } else {
                $commitmentRaw = (int) ($commitmentCfg['weekly_off_or_holiday_points'] ?? 12);
                $explanations['commitment'][] = "Weekly off / holiday / Extra day with Outcome raw ≥ {$outcomeFloor} → +{$commitmentRaw}";
            }
        } elseif ($offRoster) {
            $explanations['commitment'][] = "Off-roster day but Outcome raw {$outcomeRaw} < floor {$outcomeFloor} → 0 (presence alone does not score)";
        } else {
            $explanations['commitment'][] = 'Working day — no Extra/Leave badge points';
        }

        $otMinutes = intdiv(max(0, $inputs->overtimeSeconds), 60);
        $otCap = (int) ($commitmentCfg['overtime_minutes_soft_cap'] ?? 120);
        if ($otMinutes > 0 && $outcomeRaw >= $outcomeFloor) {
            $soft = (int) ($commitmentCfg['overtime_soft_points'] ?? 4);
            $commitmentRaw += $soft;
            $explanations['commitment'][] = "Post-shift OT {$otMinutes}m (payroll overtime_seconds; soft +{$soft}, not XT) with outcome floor met";
        } elseif ($otMinutes > 0) {
            $explanations['commitment'][] = "Post-shift OT {$otMinutes}m present but outcome floor not met → no soft points";
        }

        // --- Quality ---
        $qualityRaw = (int) ($qualityCfg['base'] ?? 100);
        $reopenPenalty = (int) ($qualityCfg['reopen_penalty'] ?? 15);
        $qualityRaw -= $inputs->reopenCount * $reopenPenalty;
        $qualityRaw = max(0, $qualityRaw);

        if ($inputs->resolvedCount < (int) ($qualityCfg['min_resolves_for_rank'] ?? 1) && $inputs->closedCount === 0) {
            $explanations['quality'][] = 'No resolves/closes — Quality held at base with reopen penalties only (low volume)';
        }
        $explanations['quality'][] = sprintf(
            'Base %d − (reopens %d × %d) = %d',
            (int) ($qualityCfg['base'] ?? 100),
            $inputs->reopenCount,
            $reopenPenalty,
            $qualityRaw,
        );

        $outcomeScore = $this->normalize($outcomeRaw, (int) ($normalize['outcome'] ?? 40));
        $reachScore = $this->normalize($reachRaw, (int) ($normalize['reach'] ?? 12));
        $contributionScore = $this->normalize($contribRaw, (int) ($normalize['contribution'] ?? 40));
        $commitmentScore = $this->normalize($commitmentRaw, (int) ($normalize['commitment'] ?? 20));
        $qualityScore = $this->normalize($qualityRaw, (int) ($normalize['quality'] ?? 100));

        $explanations['outcome'][] = "Normalized Outcome score = {$outcomeScore}/100 (ceiling ".((int) ($normalize['outcome'] ?? 40)).')';
        $explanations['reach'][] = "Normalized Reach score = {$reachScore}/100";
        $explanations['contribution'][] = "Normalized Contribution score = {$contributionScore}/100";
        $explanations['commitment'][] = "Normalized Commitment score = {$commitmentScore}/100";
        $explanations['quality'][] = "Normalized Quality score = {$qualityScore}/100";

        $wOut = (float) ($weights['outcome'] ?? 0.35);
        $wRch = (float) ($weights['reach'] ?? 0.20);
        $wCtb = (float) ($weights['contribution'] ?? 0.20);
        $wCmt = (float) ($weights['commitment'] ?? 0.10);
        $wQlt = (float) ($weights['quality'] ?? 0.15);

        $composite = round(
            $wOut * $outcomeScore
            + $wRch * $reachScore
            + $wCtb * $contributionScore
            + $wCmt * $commitmentScore
            + $wQlt * $qualityScore,
            2,
        );

        $explanations['composite'][] = sprintf(
            'Composite = %.2f×%d + %.2f×%d + %.2f×%d + %.2f×%d + %.2f×%d = %.2f',
            $wOut,
            $outcomeScore,
            $wRch,
            $reachScore,
            $wCtb,
            $contributionScore,
            $wCmt,
            $commitmentScore,
            $wQlt,
            $qualityScore,
            $composite,
        );

        return new PerformanceScoreResult(
            userId: $inputs->userId,
            workDate: $inputs->workDate,
            version: (string) config('performance_intelligence.version', 'phase0.1'),
            outcomeScore: $outcomeScore,
            reachScore: $reachScore,
            contributionScore: $contributionScore,
            commitmentScore: $commitmentScore,
            qualityScore: $qualityScore,
            compositeScore: $composite,
            breakdown: [
                'outcome_raw' => $outcomeRaw,
                'reach_raw' => $reachRaw,
                'contribution_raw' => $contribRaw,
                'commitment_raw' => $commitmentRaw,
                'quality_raw' => $qualityRaw,
                'weights' => [
                    'outcome' => $wOut,
                    'reach' => $wRch,
                    'contribution' => $wCtb,
                    'commitment' => $wCmt,
                    'quality' => $wQlt,
                ],
            ],
            inputs: $inputs,
            explanations: $explanations,
            featureFlags: [
                'PERFORMANCE_INTELLIGENCE_ENABLED' => (bool) config('performance_intelligence.enabled', false),
                'version' => (string) config('performance_intelligence.version', 'phase0.1'),
            ],
            calculationDurationMs: $durationMs,
        );
    }

    private function normalize(int|float $raw, int $ceiling): int
    {
        if ($ceiling <= 0) {
            return 0;
        }

        return (int) min(100, (int) round(($raw / $ceiling) * 100));
    }
}
