<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;

/**
 * Classifies RadiumBox FAILED sync rows for watchdog Critical vs backlog metrics.
 *
 * Fail-closed: ambiguous clocks or identifiers never become actionable Critical.
 */
final class WatchdogRadiumBoxSyncFailureClassifier
{
    public const BUCKET_ACTIONABLE = 'actionable';

    public const BUCKET_AGING = 'aging';

    public const BUCKET_DEBT = 'debt';

    public const BUCKET_EXCLUDED_PREFIX = 'excluded_prefix';

    public const PREFIX_CF_PAY = 'CFPay_';

    public function actionableHours(): int
    {
        return max(1, (int) config('ira.watchdog.actionable_hours', 48));
    }

    public function staleDays(): int
    {
        return max(1, (int) config('ira.watchdog.stale_days', 30));
    }

    public function isCriticalEligiblePublicOrderId(string $orderId): bool
    {
        $trimmed = trim($orderId);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, self::PREFIX_CF_PAY)) {
            return false;
        }

        return str_starts_with($trimmed, 'RD') || str_starts_with($trimmed, 'RB');
    }

    public function isExcludedDebtPrefix(string $orderId): bool
    {
        return str_starts_with(trim($orderId), self::PREFIX_CF_PAY);
    }

    /**
     * Primary: radiumbox_last_sync_at. Fallback: created_at only when last_sync_at is absent.
     */
    public function resolveRecencyInstant(?Carbon $lastSyncAt, ?Carbon $createdAt, ?Carbon $at = null): ?Carbon
    {
        $at ??= now();

        $clock = $lastSyncAt ?? $createdAt;

        if ($clock === null) {
            return null;
        }

        if ($clock->greaterThan($at)) {
            return null;
        }

        return $clock;
    }

    public function bucketForRecency(?Carbon $recency, ?Carbon $at = null): string
    {
        if ($recency === null) {
            return self::BUCKET_DEBT;
        }

        $at ??= now();
        $actionableCutoff = $at->copy()->subHours($this->actionableHours());
        $staleCutoff = $at->copy()->subDays($this->staleDays());

        if ($recency->greaterThanOrEqualTo($actionableCutoff)) {
            return self::BUCKET_ACTIONABLE;
        }

        if ($recency->greaterThanOrEqualTo($staleCutoff)) {
            return self::BUCKET_AGING;
        }

        return self::BUCKET_DEBT;
    }

    /**
     * @return array{bucket: string, eligible_critical: bool}
     */
    public function classifyRow(
        string $publicOrderId,
        ?Carbon $lastSyncAt,
        ?Carbon $createdAt,
        ?Carbon $at = null,
    ): array {
        if ($this->isExcludedDebtPrefix($publicOrderId)) {
            $recency = $this->resolveRecencyInstant($lastSyncAt, $createdAt, $at);

            return [
                'bucket' => self::BUCKET_EXCLUDED_PREFIX,
                'eligible_critical' => false,
                'recency_bucket' => $this->bucketForRecency($recency, $at),
            ];
        }

        $recency = $this->resolveRecencyInstant($lastSyncAt, $createdAt, $at);
        $bucket = $this->bucketForRecency($recency, $at);
        $eligibleCritical = $this->isCriticalEligiblePublicOrderId($publicOrderId)
            && $bucket === self::BUCKET_ACTIONABLE;

        return [
            'bucket' => $bucket,
            'eligible_critical' => $eligibleCritical,
            'recency_bucket' => $bucket,
        ];
    }
}
