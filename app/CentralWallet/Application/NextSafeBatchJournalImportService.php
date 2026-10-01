<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NextSafeBatchJournalImportService
{
    public function __construct(
        private readonly NextSafeBatchManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array{imported: int, skipped: int, prepared: int, batch_id: string}
     */
    public function import(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $batchId = (string) $manifest['batch_id'];
        $imported = 0;
        $skipped = 0;
        $prepared = 0;

        DB::transaction(function () use ($manifest, $batchId, &$imported, &$skipped, &$prepared): void {
            foreach ($manifest['rows'] as $row) {
                $refundId = (int) $row['refund_id'];
                $existing = CentralWalletRefundMigration::query()
                    ->where('refund_id', $refundId)
                    ->first();

                if ($existing !== null) {
                    $skipped++;

                    continue;
                }

                $deskCustomerId = (string) $row['desk_customer_id'];
                $cwid = (string) $row['cwid'];
                $this->assertCustomerWalletLink($deskCustomerId, $cwid);
                $this->assertActiveAccountLink($row);

                CentralWalletRefundMigration::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'refund_id' => $refundId,
                    'refund_reference' => (string) $row['desk_refund_reference'],
                    'amount' => (string) $row['refund_amount'],
                    'source_type' => 'spoke_wallet',
                    'source_application' => (string) $row['source_application'],
                    'source_wallet_id' => (int) $row['source_wallet_id'],
                    'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
                    'desk_customer_id' => $deskCustomerId,
                    'cwid' => $cwid,
                    'lane' => RefundMigrationLane::Lane1SpokeCutover,
                    'status' => RefundMigrationStatus::Prepared,
                    'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
                    'prepared_at' => now(),
                    'metadata' => [
                        'migration_lane' => 'LANE_A_SPOKE_DEBIT',
                        'preparation_prompt' => 'RadiumDesk-P-30-10-24',
                        'site' => $row['site'] ?? null,
                        'local_user_id' => $row['local_user_id'] ?? null,
                    ],
                ]);

                $imported++;
                $prepared++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'prepared' => $prepared,
            'batch_id' => $batchId,
        ];
    }

    private function assertCustomerWalletLink(string $deskCustomerId, string $cwid): void
    {
        $customer = CentralCustomer::query()->find($deskCustomerId);
        if ($customer === null) {
            throw new InvalidArgumentException('desk_customer_not_found');
        }

        if ($customer->central_wallet_id !== $cwid) {
            throw new InvalidArgumentException('cwid_does_not_belong_to_customer');
        }

        if (CentralWallet::query()->find($cwid) === null) {
            throw new InvalidArgumentException('cwid_not_found');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertActiveAccountLink(array $row): void
    {
        $site = (string) ($row['site'] ?? '');
        $localUserId = (string) ($row['local_user_id'] ?? '');
        $deskCustomerId = (string) ($row['desk_customer_id'] ?? '');
        $cwid = (string) ($row['cwid'] ?? '');

        if ($site === '' || $localUserId === '') {
            throw new InvalidArgumentException('next_safe_batch_account_link_context_required');
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('local_user_id', $localUserId)
            ->where('desk_customer_id', $deskCustomerId)
            ->where('central_wallet_id', $cwid)
            ->where('status', 'active')
            ->first();

        if ($link === null) {
            throw new InvalidArgumentException('next_safe_batch_account_link_missing');
        }
    }
}
