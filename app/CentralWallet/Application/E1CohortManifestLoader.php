<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class E1CohortManifestLoader
{
    public const COHORT_ID = 'e1-identity-required-wallet-resolved-p30-10-30';

    public const EXPECTED_REFUNDS = 168;

    public const EXPECTED_AMOUNT = '92811.00';

    /**
     * @return array{
     *     cohort_id: string,
     *     refund_count: int,
     *     amount: string,
     *     rows: list<array<string, mixed>>,
     *     by_refund_id: array<int, array<string, mixed>>,
     *     by_site_user: array<string, list<array<string, mixed>>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.e1_identity_migration.verification_cohort_manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('e1_verification_cohort_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('e1_verification_cohort_manifest_invalid');
        }

        $rows = array_values($decoded['rows']);
        $this->validateRows($rows);

        $byRefundId = [];
        $bySiteUser = [];
        foreach ($rows as $row) {
            $refundId = (int) $row['refund_id'];
            $byRefundId[$refundId] = $row;

            $site = strtolower(trim((string) ($row['site'] ?? '')));
            $localUserId = trim((string) ($row['local_user_id'] ?? ''));
            if ($site !== '' && $localUserId !== '') {
                $bySiteUser[$this->siteUserKey($site, $localUserId)][] = $row;
            }
        }

        return [
            'cohort_id' => (string) ($decoded['cohort_id'] ?? self::COHORT_ID),
            'refund_count' => count($rows),
            'amount' => $this->sumAmounts($rows),
            'rows' => $rows,
            'by_refund_id' => $byRefundId,
            'by_site_user' => $bySiteUser,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySiteUser(array $manifest, string $siteCode, string $localUserId): array
    {
        $key = $this->siteUserKey($siteCode, $localUserId);

        return $manifest['by_site_user'][$key] ?? [];
    }

    public function siteUserKey(string $siteCode, string $localUserId): string
    {
        return strtolower(trim($siteCode)).':'.trim($localUserId);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function validateRows(array $rows): void
    {
        $expectedRefunds = (int) config(
            'central_wallet.e1_identity_migration.expected_count',
            self::EXPECTED_REFUNDS,
        );
        $expectedAmount = (string) config(
            'central_wallet.e1_identity_migration.expected_amount',
            self::EXPECTED_AMOUNT,
        );

        if (count($rows) !== $expectedRefunds) {
            throw new InvalidArgumentException('e1_verification_cohort_refund_count_mismatch');
        }

        $total = $this->sumAmounts($rows);
        if (bccomp($total, $expectedAmount, 2) !== 0) {
            throw new InvalidArgumentException('e1_verification_cohort_amount_mismatch');
        }

        $seenRefundIds = [];
        foreach ($rows as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('e1_verification_cohort_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            $site = trim((string) ($row['site'] ?? ''));
            $localUserId = trim((string) ($row['local_user_id'] ?? ''));
            if ($site === '' || $localUserId === '') {
                throw new InvalidArgumentException('e1_verification_cohort_site_user_required');
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
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('e1_verification_cohort_amount_invalid');
            }
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }
}
