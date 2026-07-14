<?php

namespace App\Services;

use App\Data\Refunds\RefundProfileDefinition;
use App\Enums\RefundDeductionProfile;
use InvalidArgumentException;

class RefundProfileRegistry
{
    /**
     * @return list<RefundProfileDefinition>
     */
    public function all(): array
    {
        $profiles = [];

        foreach (RefundDeductionProfile::cases() as $profile) {
            $profiles[] = $this->get($profile->value);
        }

        return $profiles;
    }

    public function get(string $key): RefundProfileDefinition
    {
        $config = config('refunds.profiles.'.$key);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown refund profile [{$key}].");
        }

        $cancellation = (float) ($config['cancellation_charges'] ?? 0);
        $applyGst = (bool) ($config['apply_gst_rate'] ?? false);
        $gstConfigured = $config['gst_on_cancellation'] ?? null;
        $gstRate = (float) config('refunds.gst_rate', 0.18);

        $gst = $gstConfigured !== null
            ? (float) $gstConfigured
            : ($applyGst ? round($cancellation * $gstRate, 2) : 0.0);

        return new RefundProfileDefinition(
            key: $key,
            label: (string) ($config['label'] ?? $key),
            cancellationCharges: $cancellation,
            gstOnCancellation: $gst,
            otherDeduction: (float) ($config['other_deduction'] ?? 0),
            applyGstRate: $applyGst,
        );
    }

    public function gstRate(): float
    {
        return (float) config('refunds.gst_rate', 0.18);
    }
}
