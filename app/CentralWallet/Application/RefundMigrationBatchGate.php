<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigrationResolution;
use App\Models\RefundRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class RefundMigrationBatchGate
{
    public function __construct(
        private readonly RefundMigrationManifestLoader $manifestLoader,
    ) {}

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    public function evaluate(string $ownerApprovalRef, ?string $manifestPath = null): array
    {
        $blockers = [];

        if (trim($ownerApprovalRef) === '') {
            $blockers[] = ['code' => 'owner_approval_required', 'message' => 'owner_approval_ref is required'];
        }

        if (! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $blockers[] = ['code' => 'refund_migration_execution_disabled', 'message' => 'Execution flag is disabled'];
        }

        try {
            $this->manifestLoader->load($manifestPath);
        } catch (InvalidArgumentException $exception) {
            $blockers[] = ['code' => $exception->getMessage(), 'message' => 'Manifest validation failed'];

            return $blockers;
        }

        $journalRows = CentralWalletRefundMigration::query()
            ->orderBy('refund_id')
            ->get();

        if ($journalRows->count() !== RefundMigrationManifestLoader::EXPECTED_COUNT) {
            $blockers[] = [
                'code' => 'journal_count_mismatch',
                'message' => 'Expected '.RefundMigrationManifestLoader::EXPECTED_COUNT.' journal rows, found '.$journalRows->count(),
            ];
        }

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        if (bccomp($journalTotal, RefundMigrationManifestLoader::EXPECTED_AMOUNT, 2) !== 0) {
            $blockers[] = [
                'code' => 'journal_amount_mismatch',
                'message' => 'Journal total '.$journalTotal.' != '.RefundMigrationManifestLoader::EXPECTED_AMOUNT,
            ];
        }

        $manifest = $this->manifestLoader->load($manifestPath);
        $manifestByRefundId = collect($manifest['rows'])->keyBy('refund_id');

        foreach ($journalRows as $journal) {
            $blockers = array_merge($blockers, $this->evaluateRow($journal, $manifestByRefundId, $ownerApprovalRef));
        }

        $this->assertNoDuplicateLedgerCredits($journalRows, $blockers);

        return $blockers;
    }

    public function assertReady(string $ownerApprovalRef, ?string $manifestPath = null): void
    {
        $blockers = $this->evaluate($ownerApprovalRef, $manifestPath);
        if ($blockers !== []) {
            throw new InvalidArgumentException('batch_gate_failed:'.json_encode($blockers, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $manifestByRefundId
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    private function evaluateRow(
        CentralWalletRefundMigration $journal,
        Collection $manifestByRefundId,
        string $ownerApprovalRef,
    ): array {
        $blockers = [];
        $refundId = (int) $journal->refund_id;

        $manifestRow = $manifestByRefundId->get($refundId);
        if ($manifestRow === null) {
            return [['code' => 'refund_not_in_manifest', 'refund_id' => $refundId, 'message' => 'Refund missing from manifest']];
        }

        if (bccomp((string) $journal->amount, (string) $manifestRow['amount'], 2) !== 0) {
            $blockers[] = [
                'code' => 'amount_mismatch',
                'refund_id' => $refundId,
                'message' => 'Journal amount does not match manifest',
            ];
        }

        if ($journal->desk_customer_id === null) {
            $blockers[] = [
                'code' => 'missing_desk_customer_id',
                'refund_id' => $refundId,
                'message' => 'Desk Customer ID is required',
            ];
        }

        if ($journal->cwid === null) {
            $blockers[] = [
                'code' => 'missing_cwid',
                'refund_id' => $refundId,
                'message' => 'CWID is required',
            ];
        }

        if ($journal->desk_customer_id !== null && $journal->cwid !== null) {
            $customer = CentralCustomer::query()->find($journal->desk_customer_id);
            if ($customer === null || $customer->central_wallet_id !== $journal->cwid) {
                $blockers[] = [
                    'code' => 'cwid_customer_mismatch',
                    'refund_id' => $refundId,
                    'message' => 'CWID does not belong to Desk Customer',
                ];
            }
        }

        if ($journal->lane->requiresOwnerResolution()) {
            $resolution = CentralWalletRefundMigrationResolution::query()
                ->where('refund_id', $refundId)
                ->first();

            if ($resolution === null) {
                $blockers[] = [
                    'code' => 'owner_resolution_required',
                    'refund_id' => $refundId,
                    'message' => 'Owner-approved resolution is required for lane '.$journal->lane->value,
                ];
            }
        }

        if ($journal->lane === RefundMigrationLane::Lane1SpokeCutover) {
            if ($journal->source_application === null || $journal->source_wallet_id === null) {
                $blockers[] = [
                    'code' => 'missing_spoke_source',
                    'refund_id' => $refundId,
                    'message' => 'Lane 1 requires source_application and source_wallet_id',
                ];
            }
        }

        if ($journal->status === RefundMigrationStatus::Pending) {
            $blockers[] = [
                'code' => 'status_pending',
                'refund_id' => $refundId,
                'message' => 'Migration row is still pending target assignment',
            ];
        }

        if ($journal->status === RefundMigrationStatus::Reconciled || $journal->status === RefundMigrationStatus::CwCredited) {
            // already migrated — batch gate for fresh execution should flag
            if ($journal->destination_ledger_entry_id !== null) {
                $blockers[] = [
                    'code' => 'already_credited',
                    'refund_id' => $refundId,
                    'message' => 'Refund already has a Central Wallet credit',
                ];
            }
        }

        $refund = RefundRequest::query()->find($refundId);
        if ($refund === null) {
            $blockers[] = [
                'code' => 'refund_not_found',
                'refund_id' => $refundId,
                'message' => 'Refund request not found',
            ];
        } elseif (
            $refund->approved_refund_method?->value !== 'wallet'
            || ! in_array($refund->status?->value, ['closed', 'completed'], true)
        ) {
            $blockers[] = [
                'code' => 'refund_not_terminal_wallet',
                'refund_id' => $refundId,
                'message' => 'Refund is not a terminal wallet refund',
            ];
        } elseif (bccomp((string) $refund->refund_amount, (string) $journal->amount, 2) !== 0) {
            $blockers[] = [
                'code' => 'refund_amount_changed',
                'refund_id' => $refundId,
                'message' => 'Live refund amount does not match journal',
            ];
        }

        if ($journal->owner_approval_ref !== null && $journal->owner_approval_ref !== $ownerApprovalRef) {
            $blockers[] = [
                'code' => 'owner_approval_ref_mismatch',
                'refund_id' => $refundId,
                'message' => 'Row owner_approval_ref does not match batch approval ref',
            ];
        }

        return $blockers;
    }

    /**
     * @param  Collection<int, CentralWalletRefundMigration>  $journalRows
     * @param  list<array{code: string, refund_id?: int, message: string}>  $blockers
     */
    private function assertNoDuplicateLedgerCredits(Collection $journalRows, array &$blockers): void
    {
        foreach ($journalRows as $journal) {
            $exists = CentralWalletLedgerEntry::query()
                ->where('source_system', 'radium-desk')
                ->where('source_reference', $journal->source_reference)
                ->where('entry_type', 'credit')
                ->exists();

            if ($exists && $journal->destination_ledger_entry_id === null) {
                $blockers[] = [
                    'code' => 'duplicate_ledger_credit',
                    'refund_id' => (int) $journal->refund_id,
                    'message' => 'Ledger credit already exists without journal linkage',
                ];
            }
        }
    }
}
