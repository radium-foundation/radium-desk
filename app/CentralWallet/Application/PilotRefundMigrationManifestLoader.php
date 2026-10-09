<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class PilotRefundMigrationManifestLoader
{
    /**
     * @return array{
     *     batch_id: string,
     *     cohort_id: string,
     *     refund_count: int,
     *     manifest_rows_sha256: string,
     *     allowed_refund_ids: list<int>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.pilot_refund_migration.manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('pilot_refund_migration_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('pilot_refund_migration_manifest_invalid');
        }

        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): void
    {
        if (($manifest['immutable'] ?? false) !== true) {
            throw new InvalidArgumentException('pilot_refund_migration_manifest_not_immutable');
        }

        $rows = $manifest['rows'];
        $count = (int) ($manifest['refund_count'] ?? 0);
        if ($count !== count($rows) || $count < 1) {
            throw new InvalidArgumentException('pilot_refund_migration_count_mismatch');
        }

        if ($count > (int) config('central_wallet.pilot_refund_migration.max_rows', 1)) {
            throw new InvalidArgumentException('pilot_refund_migration_row_limit_exceeded');
        }

        $expectedHash = (string) ($manifest['manifest_rows_sha256'] ?? '');
        $actualHash = $this->hashRows($rows);
        if ($expectedHash === '' || ! hash_equals($expectedHash, $actualHash)) {
            throw new InvalidArgumentException('pilot_refund_migration_manifest_hash_mismatch');
        }

        $configuredHash = trim((string) config('central_wallet.pilot_refund_migration.manifest_rows_sha256', ''));
        if ($configuredHash !== '' && ! hash_equals($configuredHash, $actualHash)) {
            throw new InvalidArgumentException('pilot_refund_migration_config_hash_mismatch');
        }

        $allowed = $this->normalizedAllowlist($manifest);
        $seenRefundIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('pilot_refund_migration_row_invalid');
            }

            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('pilot_refund_migration_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            if (! in_array($refundId, $allowed, true)) {
                throw new InvalidArgumentException('pilot_refund_migration_refund_not_allowlisted');
            }

            if (($row['status'] ?? '') !== 'prepared') {
                throw new InvalidArgumentException('pilot_refund_migration_row_not_prepared');
            }

            if (($row['source_lane'] ?? '') !== 'LANE_A_SPOKE_DEBIT') {
                throw new InvalidArgumentException('pilot_refund_migration_lane_not_a');
            }

            $this->validatePreparedRow($row);
        }

        $manifestAllowed = array_map('intval', (array) ($manifest['allowed_refund_ids'] ?? []));
        sort($manifestAllowed);
        $sortedSeen = array_keys($seenRefundIds);
        sort($sortedSeen);
        if ($manifestAllowed !== $sortedSeen) {
            throw new InvalidArgumentException('pilot_refund_migration_allowlist_mismatch');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function hashRows(array $rows): string
    {
        $sorted = $this->sortRecursive($rows);

        return hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<int>
     */
    public function normalizedAllowlist(array $manifest): array
    {
        $fromManifest = array_map('intval', (array) ($manifest['allowed_refund_ids'] ?? []));
        $fromConfig = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) config('central_wallet.pilot_refund_migration.allowed_refund_ids', '')),
        )));

        $allowed = $fromConfig !== [] ? $fromConfig : $fromManifest;
        sort($allowed);

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function validatePreparedRow(array $row): void
    {
        $amount = (string) ($row['refund_amount'] ?? '');
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('pilot_refund_migration_amount_invalid');
        }

        foreach (['desk_refund_reference', 'order_number', 'site', 'order_resolved_user_id', 'desk_customer_id', 'cwid'] as $key) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                throw new InvalidArgumentException('pilot_refund_migration_missing_'.$key);
            }
        }

        $sourceWalletId = (int) ($row['source_wallet_id'] ?? 0);
        $execId = trim((string) ($row['execution_transaction_id'] ?? ''));
        if ($sourceWalletId <= 0 || $execId === '' || (string) $sourceWalletId !== $execId) {
            throw new InvalidArgumentException('pilot_refund_migration_execution_transaction_mismatch');
        }

        $balanceBefore = (string) ($row['source_balance_before'] ?? '');
        if (bccomp($balanceBefore, $amount, 2) !== 0) {
            throw new InvalidArgumentException('pilot_refund_migration_source_balance_mismatch');
        }

        if (($row['site'] ?? '') !== 'rdservice.in') {
            throw new InvalidArgumentException('pilot_refund_migration_requires_rdservice_in');
        }
    }
}
