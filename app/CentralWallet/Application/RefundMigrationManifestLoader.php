<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class RefundMigrationManifestLoader
{
    public const EXPECTED_COUNT = 292;

    public const EXPECTED_AMOUNT = '165708.00';

    public const BATCH_ID = 'desk-refund-wallet-migration-292-p30-09-25';

    /**
     * @return array{
     *     batch_id: string,
     *     population_count: int,
     *     population_amount: string,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.refund_migration.manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('refund_migration_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('refund_migration_manifest_invalid');
        }

        $this->validateTotals($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validateTotals(array $manifest): void
    {
        $rows = $manifest['rows'];
        if (count($rows) !== self::EXPECTED_COUNT) {
            throw new InvalidArgumentException('refund_migration_manifest_count_mismatch');
        }

        $total = '0.00';
        $seenRefundIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('refund_migration_manifest_row_invalid');
            }

            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('refund_migration_manifest_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            $amount = (string) ($row['amount'] ?? '');
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('refund_migration_manifest_amount_invalid');
            }

            $total = bcadd($total, $amount, 2);
        }

        if (bccomp($total, self::EXPECTED_AMOUNT, 2) !== 0) {
            throw new InvalidArgumentException('refund_migration_manifest_amount_mismatch');
        }

        $batchId = (string) ($manifest['batch_id'] ?? '');
        if ($batchId !== self::BATCH_ID) {
            throw new InvalidArgumentException('refund_migration_manifest_batch_mismatch');
        }
    }
}
