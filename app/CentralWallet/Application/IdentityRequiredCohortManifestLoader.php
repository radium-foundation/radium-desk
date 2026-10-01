<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class IdentityRequiredCohortManifestLoader
{
    public const COHORT_ID = 'identity-required-220-p30-10-16';

    public const DEFAULT_EXPECTED_REFUNDS = 220;

    public const DEFAULT_EXPECTED_AMOUNT = '127328.00';

    /**
     * @return array{
     *     cohort_id: string,
     *     refund_count: int,
     *     amount: string,
     *     rows: list<array<string, mixed>>,
     *     by_site_user: array<string, array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.identity_required_cohort.campaign_manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('identity_required_cohort_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('identity_required_cohort_manifest_invalid');
        }

        $rows = array_values(array_filter(
            $decoded['rows'],
            static fn (mixed $row): bool => is_array($row) && (string) ($row['classification'] ?? '') === 'B',
        ));

        $this->validateRows($rows);

        $bySiteUser = [];
        foreach ($rows as $row) {
            $site = (string) ($row['site'] ?? '');
            $localUserId = (string) ($row['local_user_id'] ?? '');
            if ($site === '' || $localUserId === '') {
                continue;
            }

            $key = $this->siteUserKey($site, $localUserId);
            if (! isset($bySiteUser[$key])) {
                $bySiteUser[$key] = [
                    'site' => $site,
                    'local_user_id' => $localUserId,
                    'refund_ids' => [],
                    'refund_amounts' => [],
                    'refund_total' => '0.00',
                    'source_wallet_resolved' => true,
                    'spendable_balance' => '0.00',
                ];
            }

            $refundId = (int) $row['refund_id'];
            $bySiteUser[$key]['refund_ids'][] = $refundId;
            $bySiteUser[$key]['refund_total'] = bcadd(
                $bySiteUser[$key]['refund_total'],
                (string) ($row['amount'] ?? '0'),
                2,
            );

            $spendable = $this->normalizeAmount($row['current_source_spendable_balance'] ?? null);
            if ($spendable === null) {
                $bySiteUser[$key]['source_wallet_resolved'] = false;
                $bySiteUser[$key]['refund_amounts'][$refundId] = (string) ($row['amount'] ?? '0');
            } else {
                $bySiteUser[$key]['refund_amounts'][$refundId] = $spendable;
                $bySiteUser[$key]['spendable_balance'] = bcadd(
                    $bySiteUser[$key]['spendable_balance'],
                    $spendable,
                    2,
                );
            }
        }

        return [
            'cohort_id' => self::COHORT_ID,
            'refund_count' => count($rows),
            'amount' => $this->sumAmounts($rows),
            'rows' => $rows,
            'by_site_user' => $bySiteUser,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSiteUser(array $manifest, string $siteCode, string $localUserId): ?array
    {
        $key = $this->siteUserKey($siteCode, $localUserId);

        return $manifest['by_site_user'][$key] ?? null;
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
            'central_wallet.identity_required_cohort.expected_refunds',
            self::DEFAULT_EXPECTED_REFUNDS,
        );
        $expectedAmount = (string) config(
            'central_wallet.identity_required_cohort.expected_amount',
            self::DEFAULT_EXPECTED_AMOUNT,
        );

        if (count($rows) !== $expectedRefunds) {
            throw new InvalidArgumentException('identity_required_cohort_refund_count_mismatch');
        }

        $total = $this->sumAmounts($rows);
        if (bccomp($total, $expectedAmount, 2) !== 0) {
            throw new InvalidArgumentException('identity_required_cohort_amount_mismatch');
        }

        $seenRefundIds = [];
        foreach ($rows as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('identity_required_cohort_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sumAmounts(array $rows): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $amount = (string) ($row['amount'] ?? '0');
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('identity_required_cohort_amount_invalid');
            }
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }

    private function normalizeAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = (string) $value;
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return null;
        }

        if (bccomp($amount, '0', 2) < 0) {
            return null;
        }

        return bcadd($amount, '0', 2);
    }
}
