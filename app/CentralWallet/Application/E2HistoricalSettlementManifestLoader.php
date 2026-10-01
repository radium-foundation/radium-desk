<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\E2HistoricalSettlementClassification;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class E2HistoricalSettlementManifestLoader
{
    public const BATCH_ID = 'desk-refund-historical-settlement-e2-52-p30-10-20';

    public const COHORT_ID = 'e2-identity-required-manual-wallet-unresolved-p30-10-19';

    public const EXPECTED_COUNT = 52;

    public const EXPECTED_AMOUNT = '34517.00';

    public const DEFAULT_OWNER_APPROVAL_REF = 'OWNER-CW-E2-HISTORICAL-SETTLEMENT-20261001-001';

    /**
     * @return array{
     *     batch_id: string,
     *     cohort_id: string,
     *     refund_count: int,
     *     refund_amount: string,
     *     manifest_rows_sha256: string,
     *     owner_approval_ref: string,
     *     settlement_classification: string,
     *     forensic_report_ref: string,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.e2_historical_settlement.manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('e2_historical_settlement_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('e2_historical_settlement_manifest_invalid');
        }

        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): void
    {
        if ((string) ($manifest['batch_id'] ?? '') !== self::BATCH_ID) {
            throw new InvalidArgumentException('e2_historical_settlement_batch_mismatch');
        }

        if ((string) ($manifest['cohort_id'] ?? '') !== self::COHORT_ID) {
            throw new InvalidArgumentException('e2_historical_settlement_cohort_mismatch');
        }

        if ((string) ($manifest['settlement_classification'] ?? '') !== E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT) {
            throw new InvalidArgumentException('e2_historical_settlement_classification_mismatch');
        }

        $rows = $manifest['rows'];
        $expectedCount = (int) config(
            'central_wallet.e2_historical_settlement.expected_count',
            self::EXPECTED_COUNT,
        );
        if (count($rows) !== $expectedCount) {
            throw new InvalidArgumentException('e2_historical_settlement_count_mismatch');
        }

        $this->assertManifestHash($rows, (string) ($manifest['manifest_rows_sha256'] ?? ''));

        $total = '0.00';
        $seenRefundIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('e2_historical_settlement_row_invalid');
            }

            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('e2_historical_settlement_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            $amount = (string) ($row['refund_amount'] ?? '');
            if (bccomp($amount, '0', 2) <= 0) {
                throw new InvalidArgumentException('e2_historical_settlement_invalid_amount');
            }

            if ((string) ($row['settlement_classification'] ?? '') !== E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT) {
                throw new InvalidArgumentException('e2_historical_settlement_row_classification_mismatch');
            }

            if ((string) ($row['forensic_classification'] ?? '') !== 'CORROBORATING_ONLY') {
                throw new InvalidArgumentException('e2_historical_settlement_forensic_classification_mismatch');
            }

            $total = bcadd($total, $amount, 2);
        }

        $expectedAmount = (string) config(
            'central_wallet.e2_historical_settlement.expected_amount',
            self::EXPECTED_AMOUNT,
        );
        if (bccomp($total, $expectedAmount, 2) !== 0) {
            throw new InvalidArgumentException('e2_historical_settlement_amount_mismatch');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertManifestHash(array $rows, string $expectedHash): void
    {
        if ($expectedHash === '') {
            throw new InvalidArgumentException('e2_historical_settlement_manifest_hash_missing');
        }

        $actual = hash('sha256', $this->canonicalRowsJson($rows));

        if ($actual !== $expectedHash) {
            throw new InvalidArgumentException('e2_historical_settlement_manifest_hash_mismatch');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function canonicalRowsJson(array $rows): string
    {
        $sorted = $rows;
        usort($sorted, static fn (array $a, array $b): int => ((int) $a['refund_id']) <=> ((int) $b['refund_id']));

        foreach ($sorted as &$row) {
            $this->recursiveKsort($row);
        }
        unset($row);

        return json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    private function recursiveKsort(array &$value): void
    {
        if ($value === [] || array_is_list($value)) {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $this->recursiveKsort($item);
                }
            }
            unset($item);

            return;
        }

        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->recursiveKsort($item);
            }
        }
        unset($item);
    }
}
