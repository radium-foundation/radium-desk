<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class Ready4FinancialMigrationManifestLoader
{
    public const BATCH_ID = 'desk-refund-wallet-migration-type1-ready4-p30-10-12';

    public const COHORT_ID = 'type1-ready4-p30-10-12';

    public const EXPECTED_COUNT = 4;

    public const EXECUTABLE_COUNT = 3;

    public const EXPECTED_AMOUNT = '2344.00';

    public const EXECUTABLE_AMOUNT = '1495.00';

    /** @var list<int> */
    public const ALLOWED_REFUND_IDS = [268, 284, 336, 360];

    public const MANIFEST_ROWS_SHA256 = 'a9039e5deeaff76e5f721be915a2b5aba6fb98137197e76df649dbbde50c3dda';

    /**
     * @return array{
     *     batch_id: string,
     *     cohort_id: string,
     *     refund_count: int,
     *     refund_amount: string,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.ready4_financial_migration.manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('ready4_financial_migration_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('ready4_financial_migration_manifest_invalid');
        }

        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function executableRows(?string $path = null): array
    {
        $manifest = $this->load($path);

        return array_values(array_filter(
            $manifest['rows'],
            static fn (array $row): bool => ($row['status'] ?? '') === 'prepared',
        ));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): void
    {
        $batchId = (string) ($manifest['batch_id'] ?? '');
        if ($batchId !== self::BATCH_ID) {
            throw new InvalidArgumentException('ready4_financial_migration_batch_mismatch');
        }

        $cohortId = (string) ($manifest['cohort_id'] ?? '');
        if ($cohortId !== self::COHORT_ID) {
            throw new InvalidArgumentException('ready4_financial_migration_cohort_mismatch');
        }

        $rows = $manifest['rows'];
        if (count($rows) !== self::EXPECTED_COUNT) {
            throw new InvalidArgumentException('ready4_financial_migration_count_mismatch');
        }

        $this->assertManifestHash($manifest);

        $total = '0.00';
        $executableTotal = '0.00';
        $seenRefundIds = [];
        $seenCwids = [];
        $seenCustomers = [];
        $executableCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('ready4_financial_migration_row_invalid');
            }

            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('ready4_financial_migration_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            $amount = (string) ($row['refund_amount'] ?? '');
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('ready4_financial_migration_amount_invalid');
            }

            if (($row['source_lane'] ?? '') !== 'LANE_A_SPOKE_DEBIT') {
                throw new InvalidArgumentException('ready4_financial_migration_lane_not_a');
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === 'prepared') {
                $executableCount++;
                $executableTotal = bcadd($executableTotal, $amount, 2);
                $this->validateExecutableRow($row, $seenCwids, $seenCustomers);
            } elseif ($status === 'blocked') {
                if (trim((string) ($row['preparation_block_reason'] ?? '')) === '') {
                    throw new InvalidArgumentException('ready4_financial_migration_blocked_without_reason');
                }
            } else {
                throw new InvalidArgumentException('ready4_financial_migration_invalid_row_status');
            }

            $total = bcadd($total, $amount, 2);
        }

        if (bccomp($total, self::EXPECTED_AMOUNT, 2) !== 0) {
            throw new InvalidArgumentException('ready4_financial_migration_total_mismatch');
        }

        if ($executableCount !== self::EXECUTABLE_COUNT) {
            throw new InvalidArgumentException('ready4_financial_migration_executable_count_mismatch');
        }

        if (bccomp($executableTotal, self::EXECUTABLE_AMOUNT, 2) !== 0) {
            throw new InvalidArgumentException('ready4_financial_migration_executable_amount_mismatch');
        }

        $this->assertRefundAllowlist(array_keys($seenRefundIds));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $seenCwids
     * @param  array<string, true>  $seenCustomers
     */
    private function validateExecutableRow(array $row, array &$seenCwids, array &$seenCustomers): void
    {
        $cwid = (string) ($row['cwid'] ?? '');
        $deskCustomerId = (string) ($row['desk_customer_id'] ?? '');
        if ($cwid === '' || $deskCustomerId === '') {
            throw new InvalidArgumentException('ready4_financial_migration_missing_identity');
        }

        if (isset($seenCwids[$cwid])) {
            throw new InvalidArgumentException('ready4_financial_migration_duplicate_cwid');
        }
        $seenCwids[$cwid] = true;

        if (isset($seenCustomers[$deskCustomerId])) {
            throw new InvalidArgumentException('ready4_financial_migration_duplicate_customer');
        }
        $seenCustomers[$deskCustomerId] = true;

        $sourceWalletId = (int) ($row['source_wallet_id'] ?? 0);
        if ($sourceWalletId <= 0) {
            throw new InvalidArgumentException('ready4_financial_migration_missing_source_wallet');
        }

        $amount = (string) ($row['refund_amount'] ?? '');
        $balanceBefore = (string) ($row['source_balance_before'] ?? '');
        if (bccomp($balanceBefore, $amount, 2) !== 0) {
            throw new InvalidArgumentException('ready4_financial_migration_source_balance_mismatch');
        }

        if (($row['identity_status'] ?? '') !== 'IDENTITY_ESTABLISHED') {
            throw new InvalidArgumentException('ready4_financial_migration_identity_not_established');
        }

        if (($row['site'] ?? '') !== 'rdservice.in') {
            throw new InvalidArgumentException('ready4_financial_migration_executable_requires_rdin_spoke');
        }
    }

    /**
     * @param  list<int>  $refundIds
     */
    private function assertRefundAllowlist(array $refundIds): void
    {
        sort($refundIds);
        $allowed = self::ALLOWED_REFUND_IDS;
        sort($allowed);

        if ($refundIds !== $allowed) {
            throw new InvalidArgumentException('ready4_financial_migration_refund_allowlist_mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestHash(array $manifest): void
    {
        $hash = (string) ($manifest['manifest_rows_sha256'] ?? '');
        if ($hash !== self::MANIFEST_ROWS_SHA256) {
            throw new InvalidArgumentException('ready4_financial_migration_manifest_hash_mismatch');
        }
    }
}
