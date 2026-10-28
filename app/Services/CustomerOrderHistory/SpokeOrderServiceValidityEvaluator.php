<?php

namespace App\Services\CustomerOrderHistory;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Service validity from spoke-sourced fields persisted on Desk.
 *
 * Authority (see docs/rdservice-desk-order-api-integration.md and
 * RadiumBoxOrderSearchResponseMapper warranty/amc mapping):
 * - orders.amc_status (fill-missing from rd_order AMC)
 * - enrichment sync metadata warranty/amc (from spoke API via supplementalMetadata)
 *
 * Expired when spoke status is explicitly "Expired" or warranty_expiry parses to a past date.
 * Reference eligibility fails closed when validity is unknown.
 */
class SpokeOrderServiceValidityEvaluator
{
    /**
     * @param  array<string, mixed>|null  $enrichmentMetadata
     * @return array{
     *     status: string,
     *     label: string,
     *     warranty: ?string,
     *     amc: ?string,
     *     eligible_for_reference: bool,
     * }
     */
    public function evaluate(Order $order, ?array $enrichmentMetadata): array
    {
        $metadata = is_array($enrichmentMetadata) ? $enrichmentMetadata : [];

        $warranty = $this->nullableString($metadata['warranty'] ?? null);
        $amc = $this->nullableString($order->amc_status)
            ?? $this->nullableString($metadata['amc'] ?? null)
            ?? $this->nullableString($metadata['amc_status'] ?? null);

        if ($this->isExpiredStatus($warranty) || $this->isExpiredStatus($amc)) {
            return $this->result(
                status: 'expired',
                label: 'Expired (spoke status)',
                warranty: $warranty,
                amc: $amc,
                eligible: false,
            );
        }

        $warrantyExpiry = $this->parseAuthoritativeExpiryDate($warranty);
        if ($warrantyExpiry !== null && $warrantyExpiry->isPast()) {
            return $this->result(
                status: 'expired',
                label: 'Expired (warranty date)',
                warranty: $warranty,
                amc: $amc,
                eligible: false,
            );
        }

        if ($this->isActiveStatus($warranty) || $this->isActiveStatus($amc)) {
            return $this->result(
                status: 'active',
                label: 'Active (spoke status)',
                warranty: $warranty,
                amc: $amc,
                eligible: true,
            );
        }

        if ($warrantyExpiry !== null && ! $warrantyExpiry->isPast()) {
            return $this->result(
                status: 'active',
                label: 'Active (warranty date)',
                warranty: $warranty,
                amc: $amc,
                eligible: true,
            );
        }

        return $this->result(
            status: 'unknown',
            label: 'Unknown',
            warranty: $warranty,
            amc: $amc,
            eligible: false,
        );
    }

    private function isExpiredStatus(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return strtolower(trim($value)) === 'expired';
    }

    private function isActiveStatus(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, [
            'active',
            'valid',
            'under warranty',
        ], true);
    }

    private function parseAuthoritativeExpiryDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || $this->isExpiredStatus($trimmed) || $this->isActiveStatus($trimmed)) {
            return null;
        }

        $timezone = config('app.timezone', 'Asia/Kolkata');

        try {
            return Carbon::parse($trimmed, $timezone)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     warranty: ?string,
     *     amc: ?string,
     *     eligible_for_reference: bool,
     * }
     */
    private function result(
        string $status,
        string $label,
        ?string $warranty,
        ?string $amc,
        bool $eligible,
    ): array {
        return [
            'status' => $status,
            'label' => $label,
            'warranty' => $warranty,
            'amc' => $amc,
            'eligible_for_reference' => $eligible,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
