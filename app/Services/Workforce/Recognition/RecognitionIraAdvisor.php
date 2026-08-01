<?php

namespace App\Services\Workforce\Recognition;

use App\Data\Workforce\Recognition\RecognitionAdvice;
use App\Enums\RecognitionRecommendation;
use App\Models\User;

/**
 * Rule-based IRA advisor for Work Recognition.
 * Recommends only — never approves.
 */
class RecognitionIraAdvisor
{
    /**
     * @param  array<string, mixed>  $evidenceSnapshot
     */
    public function advise(User $user, array $evidenceSnapshot): RecognitionAdvice
    {
        $packId = $this->resolvePackId($user);
        $pack = config('workforce_recognition.packs.'.$packId, config('workforce_recognition.packs.support'));
        $weights = $pack['signal_weights'] ?? [];
        $signals = $evidenceSnapshot['contribution']['signals'] ?? [];

        $score = 0.0;
        $why = [];
        $businessHit = false;
        $businessIds = config('workforce_recognition.business_signal_ids', []);

        foreach ($signals as $signal) {
            if (! ($signal['available'] ?? false)) {
                continue;
            }
            $id = (string) ($signal['id'] ?? '');
            $value = (float) ($signal['value'] ?? 0);
            if ($value <= 0) {
                continue;
            }

            $weight = (float) ($weights[$id] ?? 0);
            if ($weight <= 0) {
                continue;
            }

            // Normalize: count-like signals contribute value*weight; duration uses hours.
            $contribution = $id === 'active_duration'
                ? ($value / 3600) * $weight
                : $value * $weight;

            $score += $contribution;
            $why[] = sprintf('%s (%.1f × %.1f)', $signal['label'] ?? $id, $value, $weight);

            if (in_array($id, $businessIds, true)) {
                $businessHit = true;
            }
        }

        if ($why === [] && (($evidenceSnapshot['session_count'] ?? 0) > 0)) {
            $why[] = 'Login activity only — no meaningful business signals detected';
        }

        $recommendation = $this->bandRecommendation($pack['bands'] ?? [], $score);

        $ceiling = RecognitionRecommendation::tryFrom((string) ($pack['require_business_signal_above'] ?? 'appreciation'));
        if (! $businessHit && $ceiling !== null && $this->rank($recommendation) > $this->rank($ceiling)) {
            $recommendation = $ceiling;
            $why[] = 'Capped at '.$ceiling->label().' because no business contribution signals were present';
        }

        $rationale = $this->buildRationale($recommendation, $score, $why, $evidenceSnapshot);

        return new RecognitionAdvice(
            score: round($score, 2),
            recommendation: $recommendation,
            rationale: $rationale,
            why: $why,
            departmentPack: $packId,
        );
    }

    public function resolvePackId(User $user): string
    {
        $map = config('workforce_recognition.role_pack_map', []);
        foreach ($map as $role => $packId) {
            if ($user->hasRole($role)) {
                return (string) $packId;
            }
        }

        return (string) config('workforce_recognition.default_pack', 'support');
    }

    /**
     * @param  list<array{min: float, max: float|null, recommendation: string}>  $bands
     */
    private function bandRecommendation(array $bands, float $score): RecognitionRecommendation
    {
        foreach ($bands as $band) {
            $min = (float) ($band['min'] ?? 0);
            $max = $band['max'] ?? null;
            if ($score < $min) {
                continue;
            }
            if ($max !== null && $score > (float) $max) {
                continue;
            }

            return RecognitionRecommendation::tryFrom((string) $band['recommendation'])
                ?? RecognitionRecommendation::NoBenefit;
        }

        return RecognitionRecommendation::NoBenefit;
    }

    /**
     * @param  list<string>  $why
     * @param  array<string, mixed>  $evidenceSnapshot
     */
    private function buildRationale(
        RecognitionRecommendation $recommendation,
        float $score,
        array $why,
        array $evidenceSnapshot,
    ): string {
        $parts = [
            sprintf('Recommended %s (score %.2f).', $recommendation->label(), $score),
        ];

        $summary = $evidenceSnapshot['evidence_summary'] ?? [];
        if (is_array($summary) && $summary !== []) {
            $parts[] = 'Evidence: '.implode('; ', array_slice($summary, 0, 8)).'.';
        } elseif ($why !== []) {
            $parts[] = 'Drivers: '.implode('; ', array_slice($why, 0, 8)).'.';
        } else {
            $parts[] = 'Insufficient productive contribution evidence.';
        }

        $login = (int) ($evidenceSnapshot['login_seconds'] ?? 0);
        $productive = (int) ($evidenceSnapshot['productive_seconds'] ?? 0);
        if ($login > 0 || $productive > 0) {
            $parts[] = sprintf(
                'Login %.1fh · Productive %.1fh (low weight).',
                $login / 3600,
                $productive / 3600,
            );
        }

        return implode(' ', $parts);
    }

    private function rank(RecognitionRecommendation $recommendation): int
    {
        return match ($recommendation) {
            RecognitionRecommendation::NoBenefit => 0,
            RecognitionRecommendation::Appreciation => 1,
            RecognitionRecommendation::Ot => 2,
            RecognitionRecommendation::HalfExtra => 3,
            RecognitionRecommendation::FullExtra => 4,
            RecognitionRecommendation::CompOff => 5,
            RecognitionRecommendation::Bonus => 6,
        };
    }
}
