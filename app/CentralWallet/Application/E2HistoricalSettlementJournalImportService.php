<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\E2HistoricalSettlementClassification;
use App\CentralWallet\Domain\E2HistoricalSettlementIdempotencyKey;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class E2HistoricalSettlementJournalImportService
{
    public function __construct(
        private readonly E2HistoricalSettlementManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     prepared: int,
     *     pending: int,
     *     blocked: int,
     *     batch_id: string
     * }
     */
    public function import(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $batchId = (string) $manifest['batch_id'];
        $imported = 0;
        $skipped = 0;
        $prepared = 0;
        $pending = 0;
        $blocked = 0;

        DB::transaction(function () use ($manifest, $batchId, &$imported, &$skipped, &$prepared, &$pending, &$blocked): void {
            foreach ($manifest['rows'] as $row) {
                $refundId = (int) $row['refund_id'];
                $existing = CentralWalletRefundMigration::query()
                    ->where('refund_id', $refundId)
                    ->first();

                if ($existing !== null) {
                    $skipped++;

                    continue;
                }

                $deskCustomerId = $row['desk_customer_id'] ?? null;
                $cwid = $row['cwid'] ?? null;
                $status = RefundMigrationStatus::Pending;
                $preparedAt = null;

                if (is_string($deskCustomerId) && $deskCustomerId !== '' && is_string($cwid) && $cwid !== '') {
                    $this->assertCustomerWalletLink($deskCustomerId, $cwid);
                    $this->assertActiveAccountLinkIfPresent($row, $deskCustomerId, $cwid);
                    $status = RefundMigrationStatus::Prepared;
                    $preparedAt = now();
                    $prepared++;
                } else {
                    $pending++;
                    $blocked++;
                }

                CentralWalletRefundMigration::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'refund_id' => $refundId,
                    'refund_reference' => (string) $row['desk_refund_reference'],
                    'amount' => (string) $row['refund_amount'],
                    'source_type' => 'historical_manual_refund',
                    'source_application' => null,
                    'source_wallet_id' => null,
                    'source_reference' => E2HistoricalSettlementIdempotencyKey::ledgerSourceReference($refundId),
                    'desk_customer_id' => is_string($deskCustomerId) && $deskCustomerId !== '' ? $deskCustomerId : null,
                    'cwid' => is_string($cwid) && $cwid !== '' ? $cwid : null,
                    'lane' => RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement,
                    'status' => $status,
                    'idempotency_key' => E2HistoricalSettlementIdempotencyKey::forRefund($refundId),
                    'order_number' => isset($row['order_number']) ? (string) $row['order_number'] : null,
                    'identity_class' => 'E2',
                    'prepared_at' => $preparedAt,
                    'metadata' => [
                        'cohort_id' => E2HistoricalSettlementManifestLoader::COHORT_ID,
                        'site' => $row['site'] ?? null,
                        'historical_executed_at' => $row['historical_executed_at'] ?? null,
                        'approved_refund_method' => $row['approved_refund_method'] ?? 'wallet',
                        'execution_reference_no' => $row['execution_reference_no'] ?? null,
                        'forensic_classification' => $row['forensic_classification'] ?? 'CORROBORATING_ONLY',
                        'forensic_report_ref' => $row['forensic_report_ref'] ?? E2HistoricalSettlementClassification::FORENSIC_REPORT_REF,
                        'settlement_classification' => E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT,
                        'settlement_audit_disclaimer' => E2HistoricalSettlementClassification::AUDIT_DISCLAIMER,
                        'manifest_rows_sha256' => $manifest['manifest_rows_sha256'] ?? null,
                        'migration_lane' => 'LANE_4_OWNER_APPROVED_HISTORICAL_SETTLEMENT',
                        'destination_policy' => $row['destination_policy'] ?? 'existing_trusted_desk_customer_cwid_required',
                        'blocked_reason' => $row['blocked_reason'] ?? null,
                        'source_wallet_provenance' => 'unavailable_not_reconstructed',
                    ],
                ]);

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'prepared' => $prepared,
            'pending' => $pending,
            'blocked' => $blocked,
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
    private function assertActiveAccountLinkIfPresent(array $row, string $deskCustomerId, string $cwid): void
    {
        $site = (string) ($row['site'] ?? '');
        $localUserId = $row['local_user_id'] ?? null;

        if ($site === '' || ! is_string($localUserId) || $localUserId === '') {
            return;
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('local_user_id', $localUserId)
            ->where('desk_customer_id', $deskCustomerId)
            ->where('central_wallet_id', $cwid)
            ->where('status', 'active')
            ->first();

        if ($link === null) {
            throw new InvalidArgumentException('e2_historical_settlement_account_link_missing');
        }
    }
}
