<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigrationResolution;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundMigrationTargetAssignmentService
{
    public function assignDirectTarget(
        int $refundId,
        string $deskCustomerId,
        string $cwid,
        string $ownerApprovalRef,
        string $approvedBy,
    ): CentralWalletRefundMigration {
        $this->assertCustomerWalletLink($deskCustomerId, $cwid);

        return DB::transaction(function () use ($refundId, $deskCustomerId, $cwid, $ownerApprovalRef): CentralWalletRefundMigration {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($migration->cwid !== null && $migration->cwid !== $cwid) {
                throw new InvalidArgumentException('cwid_conflict');
            }

            if ($migration->desk_customer_id !== null && $migration->desk_customer_id !== $deskCustomerId) {
                throw new InvalidArgumentException('desk_customer_conflict');
            }

            $migration->fill([
                'desk_customer_id' => $deskCustomerId,
                'cwid' => $cwid,
                'owner_approval_ref' => $ownerApprovalRef,
                'status' => $this->nextStatusAfterTargetAssignment($migration),
                'prepared_at' => $migration->prepared_at ?? now(),
            ]);
            $migration->save();

            return $migration->refresh();
        });
    }

    /**
     * @param  array{
     *     resolution_type: string,
     *     source_application?: string|null,
     *     source_wallet_id?: int|null,
     *     source_local_user_id?: string|null,
     *     desk_customer_id: string,
     *     cwid: string,
     *     owner_approval_ref: string,
     *     approved_by: string
     * }  $resolution
     */
    public function assignOwnerResolution(int $refundId, array $resolution): CentralWalletRefundMigration
    {
        $this->assertCustomerWalletLink($resolution['desk_customer_id'], $resolution['cwid']);

        return DB::transaction(function () use ($refundId, $resolution): CentralWalletRefundMigration {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $migration->lane->requiresOwnerResolution()) {
                throw new InvalidArgumentException('refund_does_not_require_owner_resolution');
            }

            if ($migration->lane === RefundMigrationLane::Lane2AmbiguityResolution) {
                if (
                    empty($resolution['source_application'])
                    || empty($resolution['source_wallet_id'])
                    || empty($resolution['source_local_user_id'])
                ) {
                    throw new InvalidArgumentException('ambiguous_resolution_requires_spoke_target');
                }

                $migration->fill([
                    'source_application' => $resolution['source_application'],
                    'source_wallet_id' => (int) $resolution['source_wallet_id'],
                    'source_type' => 'spoke_wallet',
                    'lane' => RefundMigrationLane::Lane1SpokeCutover,
                ]);
            }

            CentralWalletRefundMigrationResolution::query()->updateOrCreate(
                ['refund_id' => $refundId],
                [
                    'resolution_type' => $resolution['resolution_type'],
                    'source_application' => $resolution['source_application'] ?? null,
                    'source_wallet_id' => $resolution['source_wallet_id'] ?? null,
                    'source_local_user_id' => $resolution['source_local_user_id'] ?? null,
                    'desk_customer_id' => $resolution['desk_customer_id'],
                    'cwid' => $resolution['cwid'],
                    'owner_approval_ref' => $resolution['owner_approval_ref'],
                    'approved_by' => $resolution['approved_by'],
                    'approved_at' => now(),
                ],
            );

            $migration->fill([
                'desk_customer_id' => $resolution['desk_customer_id'],
                'cwid' => $resolution['cwid'],
                'owner_approval_ref' => $resolution['owner_approval_ref'],
                'lane' => $migration->lane === RefundMigrationLane::Lane3BlockedInsufficientEvidence
                    ? RefundMigrationLane::Lane3RefundProvenance
                    : $migration->lane,
                'status' => RefundMigrationStatus::Prepared,
                'prepared_at' => now(),
            ]);
            $migration->save();

            return $migration->refresh();
        });
    }

    private function nextStatusAfterTargetAssignment(CentralWalletRefundMigration $migration): RefundMigrationStatus
    {
        if ($migration->lane->requiresOwnerResolution() && $migration->lane !== RefundMigrationLane::Lane1SpokeCutover) {
            return RefundMigrationStatus::Pending;
        }

        return RefundMigrationStatus::Prepared;
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
    }
}
