<?php

namespace App\Services\RadiumBox;

use App\Models\Order;
use Illuminate\Support\Carbon;

class RadiumBoxEnrichmentRetryPolicy
{
    public const AUTOMATIC_WINDOW_DAYS = 7;

    public function isWithinAutomaticWindow(Order $order): bool
    {
        if ($order->created_at === null) {
            return false;
        }

        return $order->created_at->greaterThanOrEqualTo(
            now()->subDays(self::AUTOMATIC_WINDOW_DAYS),
        );
    }

    public function orderAgeDays(Order $order): float
    {
        if ($order->created_at === null) {
            return 0.0;
        }

        return round($this->orderAgeHours($order) / 24, 2);
    }

    public function orderAgeHours(Order $order): float
    {
        if ($order->created_at === null) {
            return 0.0;
        }

        return max(0, $order->created_at->diffInSeconds(now()) / 3600);
    }

    public function requiredIntervalHours(Order $order): int
    {
        $ageHours = $this->orderAgeHours($order);

        if ($ageHours < 6) {
            return 1;
        }

        if ($ageHours < 24) {
            return 4;
        }

        if ($ageHours < 72) {
            return 12;
        }

        if ($ageHours < (self::AUTOMATIC_WINDOW_DAYS * 24)) {
            return 24;
        }

        return PHP_INT_MAX;
    }

    public function hasRetryIntervalElapsed(Order $order, ?string $lastAttemptAt): bool
    {
        if ($lastAttemptAt === null || trim($lastAttemptAt) === '') {
            return true;
        }

        $lastAttempt = Carbon::parse($lastAttemptAt, config('app.timezone'));
        $hoursSinceLastAttempt = max(0, $lastAttempt->diffInSeconds(now()) / 3600);

        return $hoursSinceLastAttempt >= $this->requiredIntervalHours($order);
    }
}
