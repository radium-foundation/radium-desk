<?php

namespace App\CentralWallet\Reliability;

/**
 * Classifies account-link reconciliation variance for release-gate decisions.
 * Bulk missing migration/canonical links alone is NON-BLOCKING.
 */
final class CentralWalletAccountLinkVarianceEvaluator
{
    /**
     * @param  array<string, mixed>  $reconciliation
     * @return array{status: string, classification: string, counts: array<string, int>, blocking_reasons: list<string>}
     */
    public function evaluate(array $reconciliation): array
    {
        /** @var array<string, int> $counts */
        $counts = array_map(
            static fn ($value): int => (int) $value,
            (array) ($reconciliation['counts'] ?? []),
        );

        $blocking = [];

        foreach (['wallet_id_mismatch', 'identity_mismatch', 'spoke_only', 'duplicate_desk_link', 'duplicate_spoke_link'] as $key) {
            if (($counts[$key] ?? 0) > 0) {
                $blocking[] = $key;
            }
        }

        if ($blocking !== []) {
            return [
                'status' => 'FAIL',
                'classification' => 'SECURITY_OR_IDENTITY_CONFLICT',
                'counts' => $counts,
                'blocking_reasons' => $blocking,
            ];
        }

        $missing = (int) ($counts['desk_missing_on_spoke'] ?? 0);

        if ($missing > 0) {
            return [
                'status' => 'NON-BLOCKING',
                'classification' => 'EXPECTED_METADATA_GAP',
                'counts' => $counts,
                'blocking_reasons' => [],
            ];
        }

        return [
            'status' => 'PASS',
            'classification' => 'ALIGNED',
            'counts' => $counts,
            'blocking_reasons' => [],
        ];
    }
}
