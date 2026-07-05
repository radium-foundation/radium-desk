<?php

namespace App\Services\Operations;

use App\Data\Operations\PerformancePeriodRange;
use App\Enums\PerformancePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PerformancePeriodService
{
    public function resolve(
        PerformancePeriod|string|null $period = null,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null,
        ?Carbon $at = null,
    ): PerformancePeriodRange {
        $at ??= now();

        $periodEnum = $period instanceof PerformancePeriod
            ? $period
            : PerformancePeriod::tryFrom((string) ($period ?? config('performance.default_period', 'this_month')))
                ?? PerformancePeriod::ThisMonth;

        [$start, $end] = match ($periodEnum) {
            PerformancePeriod::Today => [
                $at->copy()->startOfDay(),
                $at->copy()->endOfDay(),
            ],
            PerformancePeriod::ThisWeek => [
                $at->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(),
                $at->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay(),
            ],
            PerformancePeriod::ThisMonth => [
                $at->copy()->startOfMonth()->startOfDay(),
                $at->copy()->endOfMonth()->endOfDay(),
            ],
            PerformancePeriod::Custom => $this->resolveCustomRange($customStart, $customEnd),
        };

        return new PerformancePeriodRange($periodEnum, $start, $end);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveCustomRange(?Carbon $customStart, ?Carbon $customEnd): array
    {
        if ($customStart === null || $customEnd === null) {
            throw ValidationException::withMessages([
                'start_date' => 'Custom ranges require both start and end dates.',
            ]);
        }

        $start = $customStart->copy()->startOfDay();
        $end = $customEnd->copy()->endOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'End date must be on or after the start date.',
            ]);
        }

        return [$start, $end];
    }
}
