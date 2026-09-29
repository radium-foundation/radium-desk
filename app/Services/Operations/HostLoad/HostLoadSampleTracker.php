<?php

namespace App\Services\Operations\HostLoad;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class HostLoadSampleTracker
{
    private const CACHE_KEY = 'host_load:watchdog:samples';

    /**
     * @return array{
     *     samples: list<array{load1: float, recorded_at: string}>,
     *     level: string,
     *     consecutive_elevated: int,
     *     consecutive_critical: int,
     *     duration_seconds: int,
     *     elevated_since: string|null
     * }
     */
    public function record(float $load1): array
    {
        $samples = Cache::get(self::CACHE_KEY, []);
        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = [
            'load1' => round($load1, 2),
            'recorded_at' => now()->toIso8601String(),
        ];

        $samples = array_slice($samples, -10);
        Cache::put(self::CACHE_KEY, $samples, now()->addHours(2));

        return $this->evaluate($samples);
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  list<array{load1: float, recorded_at: string}>  $samples
     * @return array{
     *     samples: list<array{load1: float, recorded_at: string}>,
     *     level: string,
     *     consecutive_elevated: int,
     *     consecutive_critical: int,
     *     duration_seconds: int,
     *     elevated_since: string|null
     * }
     */
    public function evaluate(array $samples): array
    {
        $elevatedThreshold = (float) config('host_load_watchdog.thresholds.elevated_load1', 2.0);
        $criticalThreshold = (float) config('host_load_watchdog.thresholds.critical_load1', 4.0);
        $elevatedRequired = max(1, (int) config('host_load_watchdog.thresholds.elevated_consecutive_samples', 3));
        $criticalRequired = max(1, (int) config('host_load_watchdog.thresholds.critical_consecutive_samples', 2));
        $normalThreshold = (float) config('host_load_watchdog.thresholds.normal_load1', 1.5);

        $consecutiveElevated = $this->consecutiveAbove($samples, $elevatedThreshold);
        $consecutiveCritical = $this->consecutiveAbove($samples, $criticalThreshold);
        $latestLoad = $samples !== [] ? (float) $samples[array_key_last($samples)]['load1'] : 0.0;

        $level = 'normal';
        if ($consecutiveCritical >= $criticalRequired && $latestLoad > $criticalThreshold) {
            $level = 'critical';
        } elseif ($consecutiveElevated >= $elevatedRequired && $latestLoad > $elevatedThreshold) {
            $level = 'elevated';
        } elseif ($latestLoad <= $normalThreshold) {
            $level = 'normal';
        }

        $elevatedSince = $this->streakStartedAt($samples, $level === 'critical' ? $criticalThreshold : $elevatedThreshold);
        $durationSeconds = 0;
        if ($elevatedSince !== null && $level !== 'normal') {
            $durationSeconds = max(0, Carbon::parse($elevatedSince)->diffInSeconds(now()));
        }

        return [
            'samples' => $samples,
            'level' => $level,
            'consecutive_elevated' => $consecutiveElevated,
            'consecutive_critical' => $consecutiveCritical,
            'duration_seconds' => $durationSeconds,
            'elevated_since' => $elevatedSince,
        ];
    }

    /**
     * @param  list<array{load1: float, recorded_at: string}>  $samples
     */
    private function consecutiveAbove(array $samples, float $threshold): int
    {
        $count = 0;

        for ($index = count($samples) - 1; $index >= 0; $index--) {
            if ((float) $samples[$index]['load1'] > $threshold) {
                $count++;

                continue;
            }

            break;
        }

        return $count;
    }

    /**
     * @param  list<array{load1: float, recorded_at: string}>  $samples
     */
    private function streakStartedAt(array $samples, float $threshold): ?string
    {
        $startedAt = null;

        for ($index = count($samples) - 1; $index >= 0; $index--) {
            if ((float) $samples[$index]['load1'] > $threshold) {
                $startedAt = (string) $samples[$index]['recorded_at'];

                continue;
            }

            break;
        }

        return $startedAt;
    }
}
