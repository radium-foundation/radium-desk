<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class E2CohortManifestLoader
{
    public const COHORT_ID = 'e2-identity-required-manual-wallet-unresolved-p30-10-19';

    public const EXPECTED_REFUNDS = 52;

    public const EXPECTED_AMOUNT = '34517.00';

    public const VERIFICATION_COHORT_MANIFEST_SHA256 = '727431121af73d41df314c963a0c214af7582497b3ade06d09233722d96a16db';

    /**
     * @return array{
     *     cohort_id: string,
     *     refund_count: int,
     *     amount: string,
     *     rows: list<array<string, mixed>>,
     *     by_refund_id: array<int, array<string, mixed>>,
     *     by_site_email_hash: array<string, list<array<string, mixed>>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.e2_historical_settlement.verification_cohort_manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('e2_verification_cohort_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('e2_verification_cohort_manifest_invalid');
        }

        $rows = array_values($decoded['rows']);
        $this->validateRows($rows);

        $byRefundId = [];
        $bySiteEmailHash = [];
        foreach ($rows as $row) {
            $refundId = (int) $row['refund_id'];
            $byRefundId[$refundId] = $row;

            $site = strtolower(trim((string) ($row['site'] ?? '')));
            $emailHash = (string) ($row['order_email_hash'] ?? '');
            if ($site !== '' && $emailHash !== '') {
                $key = $this->siteEmailHashKey($site, $emailHash);
                $bySiteEmailHash[$key][] = $row;
            }
        }

        return [
            'cohort_id' => (string) ($decoded['cohort_id'] ?? self::COHORT_ID),
            'refund_count' => count($rows),
            'amount' => $this->sumAmounts($rows),
            'rows' => $rows,
            'by_refund_id' => $byRefundId,
            'by_site_email_hash' => $bySiteEmailHash,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySiteEmailHash(array $manifest, string $siteCode, string $orderEmailHash): array
    {
        $key = $this->siteEmailHashKey($siteCode, $orderEmailHash);

        return $manifest['by_site_email_hash'][$key] ?? [];
    }

    public function siteEmailHashKey(string $siteCode, string $orderEmailHash): string
    {
        return strtolower(trim($siteCode)).':'.trim($orderEmailHash);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function validateRows(array $rows): void
    {
        $expectedRefunds = (int) config(
            'central_wallet.e2_historical_settlement.expected_count',
            self::EXPECTED_REFUNDS,
        );
        $expectedAmount = (string) config(
            'central_wallet.e2_historical_settlement.expected_amount',
            self::EXPECTED_AMOUNT,
        );

        if (count($rows) !== $expectedRefunds) {
            throw new InvalidArgumentException('e2_verification_cohort_refund_count_mismatch');
        }

        $total = $this->sumAmounts($rows);
        if (bccomp($total, $expectedAmount, 2) !== 0) {
            throw new InvalidArgumentException('e2_verification_cohort_amount_mismatch');
        }

        $seenRefundIds = [];
        foreach ($rows as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('e2_verification_cohort_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            $amount = (string) ($row['refund_amount'] ?? $row['amount'] ?? '0');
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
                throw new InvalidArgumentException('e2_verification_cohort_amount_invalid');
            }

            if (trim((string) ($row['site'] ?? '')) === '') {
                throw new InvalidArgumentException('e2_verification_cohort_site_required');
            }

            if (trim((string) ($row['order_email_hash'] ?? '')) === '') {
                throw new InvalidArgumentException('e2_verification_cohort_order_email_hash_required');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sumAmounts(array $rows): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $amount = (string) ($row['refund_amount'] ?? $row['amount'] ?? '0');
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }
}
