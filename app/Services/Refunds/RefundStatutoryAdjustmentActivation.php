<?php

namespace App\Services\Refunds;

use App\Models\RefundRequest;
use Illuminate\Support\Carbon;

/**
 * Guards statutory refund adjustment so historical refunds are not picked up
 * when the feature flag is enabled without an explicit activation timestamp.
 */
final class RefundStatutoryAdjustmentActivation
{
    public function isFeatureEnabled(): bool
    {
        return (bool) config('refunds.statutory_adjustment.enabled', false);
    }

    public function activatedAt(): ?Carbon
    {
        $raw = config('refunds.statutory_adjustment.activated_at');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return null when eligible; otherwise a skip reason for adjustment records
     */
    public function skipReasonFor(RefundRequest $refund): ?string
    {
        if (! $this->isFeatureEnabled()) {
            return 'statutory_adjustment_disabled';
        }

        $activatedAt = $this->activatedAt();
        if ($activatedAt === null) {
            return 'statutory_adjustment_not_activated';
        }

        $completedAt = $refund->executed_at ?? $refund->closed_at;
        if ($completedAt === null) {
            return 'refund_missing_completion_timestamp';
        }

        $completed = $completedAt instanceof Carbon
            ? $completedAt
            : Carbon::parse($completedAt);

        if ($completed->lt($activatedAt)) {
            return 'refund_before_statutory_adjustment_activation';
        }

        return null;
    }
}
