<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class NextSafeBatchManifestLoader
{
    public const BATCH_ID = 'desk-refund-wallet-migration-next-safe-p30-10-24';

    public const BATCH_ID_CLASS_B_P30_10_25 = 'desk-refund-wallet-migration-class-b-p30-10-25';

    /** @var list<string> */
    public const ALLOWED_BATCH_IDS = [
        self::BATCH_ID,
        self::BATCH_ID_CLASS_B_P30_10_25,
    ];

    public const EMPTY_MANIFEST_ROWS_SHA256 = '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945';

    /**
     * @return array{
     *     batch_id: string,
     *     refund_count: int,
     *     refund_amount: string,
     *     manifest_rows_sha256: string,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.next_safe_batch.manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('next_safe_batch_manifest_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('next_safe_batch_manifest_invalid');
        }

        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest): void
    {
        $batchId = (string) ($manifest['batch_id'] ?? '');
        if (! in_array($batchId, self::ALLOWED_BATCH_IDS, true)) {
            throw new InvalidArgumentException('next_safe_batch_batch_mismatch');
        }

        if ((bool) ($manifest['financial_execution'] ?? true)) {
            throw new InvalidArgumentException('next_safe_batch_manifest_must_be_preparation_only');
        }

        $rows = $manifest['rows'];
        $expectedCount = (int) ($manifest['refund_count'] ?? count($rows));
        if (count($rows) !== $expectedCount) {
            throw new InvalidArgumentException('next_safe_batch_count_mismatch');
        }

        $this->assertManifestHash($rows, (string) ($manifest['manifest_rows_sha256'] ?? ''));

        $total = '0.00';
        $seenRefundIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('next_safe_batch_row_invalid');
            }

            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId <= 0 || isset($seenRefundIds[$refundId])) {
                throw new InvalidArgumentException('next_safe_batch_duplicate_refund_id');
            }
            $seenRefundIds[$refundId] = true;

            $amount = (string) ($row['refund_amount'] ?? '0');
            if (bccomp($amount, '0', 2) <= 0) {
                throw new InvalidArgumentException('next_safe_batch_amount_invalid');
            }

            if (trim((string) ($row['desk_customer_id'] ?? '')) === '' || trim((string) ($row['cwid'] ?? '')) === '') {
                throw new InvalidArgumentException('next_safe_batch_destination_required');
            }

            if (! isset($row['source_wallet_id']) || (int) $row['source_wallet_id'] <= 0) {
                throw new InvalidArgumentException('next_safe_batch_source_wallet_required');
            }

            $total = bcadd($total, $amount, 2);
        }

        $expectedAmount = (string) ($manifest['refund_amount'] ?? $total);
        if (bccomp($total, $expectedAmount, 2) !== 0) {
            throw new InvalidArgumentException('next_safe_batch_amount_mismatch');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertManifestHash(array $rows, string $expectedHash): void
    {
        if ($expectedHash === '') {
            throw new InvalidArgumentException('next_safe_batch_manifest_hash_missing');
        }

        $sorted = $rows;
        usort($sorted, static fn (array $a, array $b): int => ((int) $a['refund_id']) <=> ((int) $b['refund_id']));

        foreach ($sorted as &$row) {
            if (is_array($row)) {
                ksort($row);
            }
        }
        unset($row);

        $actual = hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($actual !== $expectedHash) {
            throw new InvalidArgumentException('next_safe_batch_manifest_hash_mismatch');
        }
    }
}
