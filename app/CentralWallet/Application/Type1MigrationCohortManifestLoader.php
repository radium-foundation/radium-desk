<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class Type1MigrationCohortManifestLoader
{
    public const COHORT_ID = 'type1-migration-cohort-50-p30-10-04';

    public const EXPECTED_CUSTOMERS = 50;

    public const EXPECTED_REFUNDS = 50;

    public const EXPECTED_AMOUNT = '26230.00';

    /**
     * @return array{
     *     cohort_id: string,
     *     customer_count: int,
     *     refund_count: int,
     *     amount: string,
     *     customers: list<array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.type1_migration_cohort.cohort_manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('type1_migration_cohort_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['customers']) || ! is_array($decoded['customers'])) {
            throw new InvalidArgumentException('type1_migration_cohort_manifest_invalid');
        }

        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): void
    {
        $cohortId = (string) ($manifest['cohort_id'] ?? '');
        if ($cohortId !== self::COHORT_ID) {
            throw new InvalidArgumentException('type1_migration_cohort_id_mismatch');
        }

        $customers = $manifest['customers'];
        if (count($customers) !== self::EXPECTED_CUSTOMERS) {
            throw new InvalidArgumentException('type1_migration_cohort_customer_count_mismatch');
        }

        $refundTotal = '0.00';
        $seenRefundIds = [];
        $seenCustomers = [];

        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                throw new InvalidArgumentException('type1_migration_cohort_customer_invalid');
            }

            $site = (string) ($customer['site'] ?? '');
            $localUserId = (string) ($customer['local_user_id'] ?? '');
            $customerKey = $site.':'.$localUserId;
            if ($site === '' || $localUserId === '' || isset($seenCustomers[$customerKey])) {
                throw new InvalidArgumentException('type1_migration_cohort_duplicate_customer');
            }
            $seenCustomers[$customerKey] = true;

            $refundIds = $customer['refund_ids'] ?? [];
            if (! is_array($refundIds) || $refundIds === []) {
                throw new InvalidArgumentException('type1_migration_cohort_refund_ids_required');
            }

            foreach ($refundIds as $refundId) {
                $id = (int) $refundId;
                if ($id <= 0 || isset($seenRefundIds[$id])) {
                    throw new InvalidArgumentException('type1_migration_cohort_duplicate_refund_id');
                }
                $seenRefundIds[$id] = true;
            }

            $amount = (string) ($customer['refund_total'] ?? '');
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('type1_migration_cohort_amount_invalid');
            }
            $refundTotal = bcadd($refundTotal, $amount, 2);
        }

        if (count($seenRefundIds) !== self::EXPECTED_REFUNDS) {
            throw new InvalidArgumentException('type1_migration_cohort_refund_count_mismatch');
        }

        if (bccomp($refundTotal, self::EXPECTED_AMOUNT, 2) !== 0) {
            throw new InvalidArgumentException('type1_migration_cohort_amount_mismatch');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCustomer(array $manifest, string $siteCode, string $localUserId): ?array
    {
        foreach ($manifest['customers'] as $customer) {
            if (
                (string) ($customer['site'] ?? '') === $siteCode
                && (string) ($customer['local_user_id'] ?? '') === $localUserId
            ) {
                return $customer;
            }
        }

        return null;
    }
}
