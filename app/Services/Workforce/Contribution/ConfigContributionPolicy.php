<?php

namespace App\Services\Workforce\Contribution;

use App\Contracts\Workforce\ContributionPolicy;
use App\Data\Workforce\Contribution\ContributionEvaluation;
use App\Data\Workforce\Contribution\ContributionPack;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Data\Workforce\Contribution\ContributionSignalExplanation;
use App\Data\Workforce\Contribution\ContributionSnapshot;
use App\Enums\ContributionVerdict;
use App\Models\User;

/**
 * Config-backed ContributionPolicy — verdict only; never writes Attendance.
 */
class ConfigContributionPolicy implements ContributionPolicy
{
    public function __construct(
        private readonly ContributionPackResolver $packResolver,
    ) {}

    public function resolvePack(User $user): ContributionPack
    {
        return $this->packResolver->resolveForUser($user);
    }

    public function evaluate(ContributionSnapshot $snapshot): ContributionEvaluation
    {
        if (! $snapshot->engineEnabled) {
            return $this->makeEvaluation(
                snapshot: $snapshot,
                verdict: ContributionVerdict::None,
                reasons: ['Contribution engine disabled (workforce.contribution.enabled=false).'],
                thresholdsMet: [],
                thresholdsFailed: [],
                engineEnabled: false,
            );
        }

        return match ($snapshot->pack->strategy) {
            'all_of' => $this->evaluateAllOf($snapshot),
            'score' => $this->evaluateScore($snapshot),
            default => $this->evaluateAnyOf($snapshot),
        };
    }

    private function evaluateAnyOf(ContributionSnapshot $snapshot): ContributionEvaluation
    {
        $met = [];
        $failed = [];
        $reasons = [];
        $best = ContributionVerdict::None;

        foreach ($snapshot->signals as $signal) {
            if (! $snapshot->pack->isSignalEnabled($signal->id->value)) {
                continue;
            }

            if (! $signal->available || $signal->reserved) {
                $reasons[] = "{$signal->label()} skipped (unavailable/reserved).";
                continue;
            }

            $thresholds = $snapshot->pack->signalThresholds[$signal->id->value] ?? [];
            $normal = $thresholds['normal'] ?? null;
            $high = $thresholds['high'] ?? null;
            $label = $signal->id->value;

            if ($high !== null && $signal->value >= $high) {
                $met[] = "{$label}:high";
                $reasons[] = "{$signal->label()} {$signal->value} met high threshold {$high}.";
                $best = $this->maxVerdict($best, ContributionVerdict::High);
                continue;
            }

            if ($normal !== null && $signal->value >= $normal) {
                $met[] = "{$label}:normal";
                $reasons[] = "{$signal->label()} {$signal->value} met normal threshold {$normal}.";
                $best = $this->maxVerdict($best, ContributionVerdict::Normal);
                continue;
            }

            if ($signal->value > 0) {
                $failed[] = "{$label}:normal";
                $reasons[] = "{$signal->label()} {$signal->value} below normal threshold ".($normal ?? 'n/a').'.';
                $best = $this->maxVerdict($best, ContributionVerdict::Low);
                continue;
            }

            if ($normal !== null || $high !== null) {
                $failed[] = "{$label}:normal";
                $reasons[] = "{$signal->label()} is 0 — no contribution on this signal.";
            }
        }

        if ($best === ContributionVerdict::None && $reasons === []) {
            $reasons[] = 'No enabled contribution signals produced activity.';
        }

        return $this->makeEvaluation(
            snapshot: $snapshot,
            verdict: $best,
            reasons: $reasons,
            thresholdsMet: $met,
            thresholdsFailed: $failed,
            engineEnabled: true,
        );
    }

    private function evaluateAllOf(ContributionSnapshot $snapshot): ContributionEvaluation
    {
        $met = [];
        $failed = [];
        $reasons = [];
        $enabledSignals = [];

        foreach ($snapshot->signals as $signal) {
            if (! $snapshot->pack->isSignalEnabled($signal->id->value)) {
                continue;
            }
            if (! $signal->available || $signal->reserved) {
                continue;
            }
            $enabledSignals[] = $signal;
        }

        if ($enabledSignals === []) {
            return $this->makeEvaluation(
                snapshot: $snapshot,
                verdict: ContributionVerdict::None,
                reasons: ['No enabled signals in pack for all_of strategy.'],
                thresholdsMet: [],
                thresholdsFailed: [],
                engineEnabled: true,
            );
        }

        $allHigh = true;
        $allNormal = true;
        $anyActivity = false;

        foreach ($enabledSignals as $signal) {
            $thresholds = $snapshot->pack->signalThresholds[$signal->id->value] ?? [];
            $normal = $thresholds['normal'] ?? null;
            $high = $thresholds['high'] ?? null;
            $label = $signal->id->value;

            if ($signal->value > 0) {
                $anyActivity = true;
            }

            if ($high !== null && $signal->value >= $high) {
                $met[] = "{$label}:high";
                $reasons[] = "{$signal->label()} met high threshold.";
            } else {
                $allHigh = false;
                if ($high !== null) {
                    $failed[] = "{$label}:high";
                }
            }

            if ($normal !== null && $signal->value >= $normal) {
                $met[] = "{$label}:normal";
                $reasons[] = "{$signal->label()} met normal threshold.";
            } else {
                $allNormal = false;
                if ($normal !== null) {
                    $failed[] = "{$label}:normal";
                    $reasons[] = "{$signal->label()} missed normal threshold.";
                }
            }
        }

        $verdict = match (true) {
            $allHigh => ContributionVerdict::High,
            $allNormal => ContributionVerdict::Normal,
            $anyActivity => ContributionVerdict::Low,
            default => ContributionVerdict::None,
        };

        return $this->makeEvaluation(
            snapshot: $snapshot,
            verdict: $verdict,
            reasons: $reasons === [] ? ['all_of evaluation complete.'] : $reasons,
            thresholdsMet: array_values(array_unique($met)),
            thresholdsFailed: array_values(array_unique($failed)),
            engineEnabled: true,
        );
    }

    private function evaluateScore(ContributionSnapshot $snapshot): ContributionEvaluation
    {
        $ratios = [];
        $met = [];
        $failed = [];
        $reasons = [];
        $count = 0;
        $highAverage = (float) config('workforce_contribution.calibration.score.high_average', 1.5);
        $normalAverage = (float) config('workforce_contribution.calibration.score.normal_average', 1.0);

        foreach ($snapshot->signals as $signal) {
            if (! $snapshot->pack->isSignalEnabled($signal->id->value)) {
                continue;
            }
            if (! $signal->available || $signal->reserved) {
                continue;
            }

            $thresholds = $snapshot->pack->signalThresholds[$signal->id->value] ?? [];
            $normal = (float) ($thresholds['normal'] ?? 0);
            if ($normal <= 0) {
                continue;
            }

            $ratio = min(2.0, ((float) $signal->value) / $normal);
            $ratios[] = $ratio;
            $count++;
            $label = $signal->id->value;

            if ($ratio >= 1.0) {
                $met[] = "{$label}:normal";
            } else {
                $failed[] = "{$label}:normal";
                $reasons[] = "{$signal->label()} score ratio ".round($ratio, 2).' below 1.0.';
            }
        }

        if ($count === 0) {
            return $this->makeEvaluation(
                snapshot: $snapshot,
                verdict: ContributionVerdict::None,
                reasons: ['No scorable signals.'],
                thresholdsMet: [],
                thresholdsFailed: [],
                engineEnabled: true,
            );
        }

        $average = array_sum($ratios) / $count;
        $verdict = match (true) {
            $average >= $highAverage => ContributionVerdict::High,
            $average >= $normalAverage => ContributionVerdict::Normal,
            $average > 0.0 => ContributionVerdict::Low,
            default => ContributionVerdict::None,
        };

        $reasons[] = 'Score average '.round($average, 2).' → '.$verdict->label().'.';

        return $this->makeEvaluation(
            snapshot: $snapshot,
            verdict: $verdict,
            reasons: $reasons,
            thresholdsMet: $met,
            thresholdsFailed: $failed,
            engineEnabled: true,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $thresholdsMet
     * @param  list<string>  $thresholdsFailed
     */
    private function makeEvaluation(
        ContributionSnapshot $snapshot,
        ContributionVerdict $verdict,
        array $reasons,
        array $thresholdsMet,
        array $thresholdsFailed,
        bool $engineEnabled,
    ): ContributionEvaluation {
        return new ContributionEvaluation(
            userId: $snapshot->userId,
            workDate: $snapshot->workDate->copy(),
            pack: $snapshot->pack,
            verdict: $verdict,
            signals: $snapshot->signals,
            reasons: $reasons,
            thresholdsMet: $thresholdsMet,
            thresholdsFailed: $thresholdsFailed,
            engineEnabled: $engineEnabled,
            snapshot: $snapshot,
            explanations: $this->buildExplanations($snapshot),
        );
    }

    /**
     * @return list<ContributionSignalExplanation>
     */
    private function buildExplanations(ContributionSnapshot $snapshot): array
    {
        $rows = [];

        foreach ($snapshot->signals as $signal) {
            $thresholds = $snapshot->pack->signalThresholds[$signal->id->value] ?? [];
            $enabled = $snapshot->pack->isSignalEnabled($signal->id->value);
            $normal = $thresholds['normal'] ?? null;
            $high = $thresholds['high'] ?? null;

            if ($signal->reserved || ($thresholds['reserved'] ?? false) === true) {
                $rows[] = new ContributionSignalExplanation(
                    signal: $signal->id,
                    observedValue: $signal->value,
                    normalThreshold: is_numeric($normal) ? $normal : null,
                    highThreshold: is_numeric($high) ? $high : null,
                    qualified: false,
                    level: 'reserved',
                    reason: $signal->note ?? 'Reserved — not instrumented yet.',
                    available: $signal->available,
                    reserved: true,
                );
                continue;
            }

            if (! $enabled) {
                $rows[] = new ContributionSignalExplanation(
                    signal: $signal->id,
                    observedValue: $signal->value,
                    normalThreshold: is_numeric($normal) ? $normal : null,
                    highThreshold: is_numeric($high) ? $high : null,
                    qualified: false,
                    level: 'disabled',
                    reason: 'Signal disabled in pack '.$snapshot->pack->id.'.',
                    available: $signal->available,
                );
                continue;
            }

            if (! $signal->available) {
                $rows[] = new ContributionSignalExplanation(
                    signal: $signal->id,
                    observedValue: $signal->value,
                    normalThreshold: is_numeric($normal) ? $normal : null,
                    highThreshold: is_numeric($high) ? $high : null,
                    qualified: false,
                    level: 'unavailable',
                    reason: $signal->note ?? 'Collector unavailable; degraded gracefully.',
                    available: false,
                );
                continue;
            }

            [$level, $qualified, $reason] = $this->explainObserved($signal, $normal, $high);

            $rows[] = new ContributionSignalExplanation(
                signal: $signal->id,
                observedValue: $signal->value,
                normalThreshold: is_numeric($normal) ? $normal : null,
                highThreshold: is_numeric($high) ? $high : null,
                qualified: $qualified,
                level: $level,
                reason: $reason,
                available: true,
            );
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: bool, 2: string}
     */
    private function explainObserved(
        ContributionSignal $signal,
        mixed $normal,
        mixed $high,
    ): array {
        if ($high !== null && $signal->value >= $high) {
            return ['high', true, "{$signal->label()} {$signal->value} ≥ high {$high}."];
        }

        if ($normal !== null && $signal->value >= $normal) {
            return ['normal', true, "{$signal->label()} {$signal->value} ≥ normal {$normal}."];
        }

        if ($signal->value > 0) {
            return ['low', false, "{$signal->label()} {$signal->value} below normal ".($normal ?? 'n/a').'.'];
        }

        return ['none', false, "{$signal->label()} observed 0."];
    }

    private function maxVerdict(ContributionVerdict $current, ContributionVerdict $candidate): ContributionVerdict
    {
        $rank = [
            ContributionVerdict::None->value => 0,
            ContributionVerdict::Low->value => 1,
            ContributionVerdict::Normal->value => 2,
            ContributionVerdict::High->value => 3,
        ];

        return ($rank[$candidate->value] ?? 0) >= ($rank[$current->value] ?? 0)
            ? $candidate
            : $current;
    }
}
