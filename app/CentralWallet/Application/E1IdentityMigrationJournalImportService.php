<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\E1IdentityMigrationIdempotencyKey;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class E1IdentityMigrationJournalImportService
{
    public const BATCH_ID = 'desk-refund-identity-migration-e1-168-p30-10-32';

    public function __construct(
        private readonly E1CohortManifestLoader $cohortManifestLoader,
    ) {}

    /**
     * @return array{imported: int, skipped: int, pending: int, batch_id: string}
     */
    public function import(?string $manifestPath = null): array
    {
        $manifest = $this->cohortManifestLoader->load($manifestPath);
        $batchId = (string) config('central_wallet.e1_identity_migration.batch_id', self::BATCH_ID);
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($manifest, $batchId, &$imported, &$skipped): void {
            foreach ($manifest['rows'] as $row) {
                $refundId = (int) $row['refund_id'];
                $existing = CentralWalletRefundMigration::query()
                    ->where('refund_id', $refundId)
                    ->first();

                if ($existing !== null) {
                    $skipped++;

                    continue;
                }

                $sourceWalletId = isset($row['source_wallet_id']) && $row['source_wallet_id'] !== ''
                    ? (int) $row['source_wallet_id']
                    : null;
                $lane = $sourceWalletId !== null
                    ? RefundMigrationLane::Lane1SpokeCutover
                    : RefundMigrationLane::Lane3BlockedInsufficientEvidence;

                CentralWalletRefundMigration::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'refund_id' => $refundId,
                    'refund_reference' => (string) ($row['desk_refund_reference'] ?? ''),
                    'amount' => (string) ($row['refund_amount'] ?? $row['amount'] ?? '0'),
                    'source_type' => $sourceWalletId !== null ? 'spoke_wallet' : 'identity_pending',
                    'source_application' => $row['source_application'] ?? ($row['site'] ?? null),
                    'source_wallet_id' => $sourceWalletId,
                    'source_reference' => E1IdentityMigrationIdempotencyKey::ledgerSourceReference($refundId),
                    'desk_customer_id' => null,
                    'cwid' => null,
                    'lane' => $lane,
                    'status' => RefundMigrationStatus::Pending,
                    'idempotency_key' => E1IdentityMigrationIdempotencyKey::forRefund($refundId),
                    'order_number' => isset($row['order_number']) ? (string) $row['order_number'] : null,
                    'identity_class' => 'E1',
                    'prepared_at' => null,
                    'metadata' => [
                        'cohort_id' => $manifest['cohort_id'],
                        'site' => $row['site'] ?? null,
                        'local_user_id' => $row['local_user_id'] ?? null,
                        'identity_class' => 'E1',
                        'migration_lane' => 'LANE_E1_IDENTITY_MIGRATION',
                        'financial_execution' => false,
                    ],
                ]);

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'pending' => $imported,
            'batch_id' => $batchId,
        ];
    }
}
