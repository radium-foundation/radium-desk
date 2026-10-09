<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PilotRefundMigrationJournalImportService
{
    public function __construct(
        private readonly PilotRefundMigrationManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array{imported: int, skipped: int, batch_id: string}
     */
    public function import(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $batchId = (string) $manifest['batch_id'];
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($manifest, $batchId, &$imported, &$skipped): void {
            foreach ($manifest['rows'] as $row) {
                $refundId = (int) $row['refund_id'];
                $existing = CentralWalletRefundMigration::query()->where('refund_id', $refundId)->first();

                if ($existing !== null) {
                    $skipped++;

                    continue;
                }

                $cwid = (string) ($row['cwid'] ?? '');
                if (CentralWallet::query()->find($cwid) === null) {
                    throw new InvalidArgumentException('pilot_refund_migration_destination_wallet_not_found');
                }

                CentralWalletRefundMigration::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'refund_id' => $refundId,
                    'refund_reference' => (string) $row['desk_refund_reference'],
                    'amount' => (string) $row['refund_amount'],
                    'source_type' => 'spoke_wallet',
                    'source_application' => (string) $row['site'],
                    'source_wallet_id' => (int) $row['source_wallet_id'],
                    'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
                    'desk_customer_id' => (string) $row['desk_customer_id'],
                    'cwid' => $cwid,
                    'lane' => RefundMigrationLane::Lane1SpokeCutover,
                    'status' => RefundMigrationStatus::Prepared,
                    'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
                    'order_number' => (string) $row['order_number'],
                    // DB column is string(8); manifest identity_mode is descriptive only.
                    'identity_class' => 'cashfree',
                    'prepared_at' => now(),
                    'metadata' => [
                        'order_resolved_user_id' => (string) $row['order_resolved_user_id'],
                        'execution_transaction_id' => (string) $row['execution_transaction_id'],
                        'source_balance_before' => (string) $row['source_balance_before'],
                        'desk_refund_reference' => (string) $row['desk_refund_reference'],
                        'rollback_idempotency_key' => RefundMigrationIdempotencyKey::rollback($refundId),
                        'rollback_action' => RefundMigrationRollbackService::class.'::rollback',
                        'migration_lane' => 'LANE_A_SPOKE_DEBIT',
                        'pilot_manifest_cohort_id' => (string) ($manifest['cohort_id'] ?? ''),
                        'cashfree_desk_customer_without_account_link' => true,
                    ],
                ]);

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'batch_id' => $batchId,
        ];
    }
}
