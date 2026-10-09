<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class PilotRefundMigrationPreflightGate
{
    public function __construct(
        private readonly PilotRefundMigrationManifestLoader $manifestLoader,
    ) {}

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    public function evaluateManifest(?string $manifestPath = null, bool $requireExecutionFlag = false): array
    {
        $blockers = [];

        if ($requireExecutionFlag && ! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $blockers[] = [
                'code' => 'refund_migration_execution_disabled',
                'message' => 'CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED is false',
            ];
        }

        if ($requireExecutionFlag && ! (bool) config('central_wallet.balance_migration.execution_enabled', false)) {
            $blockers[] = [
                'code' => 'balance_migration_execution_disabled',
                'message' => 'CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED is false',
            ];
        }

        try {
            $manifest = $this->manifestLoader->load($manifestPath);
        } catch (InvalidArgumentException $exception) {
            return [['code' => $exception->getMessage(), 'message' => 'Manifest validation failed']];
        }

        $batchId = (string) ($manifest['batch_id'] ?? '');
        $journalRows = CentralWalletRefundMigration::query()
            ->where('batch_id', $batchId)
            ->orderBy('refund_id')
            ->get();

        if ($journalRows->count() !== count($manifest['rows'])) {
            $blockers[] = [
                'code' => 'journal_count_mismatch',
                'message' => 'Expected '.count($manifest['rows']).' journal rows, found '.$journalRows->count(),
            ];
        }

        $manifestByRefundId = collect($manifest['rows'])->keyBy('refund_id');

        foreach ($journalRows as $journal) {
            $blockers = array_merge(
                $blockers,
                $this->evaluateJournalAgainstManifest($journal, $manifestByRefundId, null),
            );
        }

        foreach ($manifest['rows'] as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($journalRows->firstWhere('refund_id', $refundId) === null) {
                $blockers = array_merge($blockers, $this->evaluateLiveRefundOnly($row));
            }
        }

        return $blockers;
    }

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    public function evaluateExecution(
        CentralWalletRefundMigration $journal,
        string $ownerApprovalRef,
        ?string $manifestPath = null,
    ): array {
        try {
            $manifest = $this->manifestLoader->load($manifestPath);
        } catch (InvalidArgumentException $exception) {
            return [['code' => $exception->getMessage(), 'message' => 'Manifest validation failed']];
        }

        $expectedOwner = trim((string) config('central_wallet.pilot_refund_migration.required_owner_approval_ref', ''));
        if ($expectedOwner !== '' && ! hash_equals($expectedOwner, trim($ownerApprovalRef))) {
            return [[
                'code' => 'owner_approval_ref_mismatch',
                'refund_id' => (int) $journal->refund_id,
                'message' => 'Owner approval reference does not match configured gate',
            ]];
        }

        $manifestByRefundId = collect($manifest['rows'])->keyBy('refund_id');

        $blockers = $this->evaluateManifest($manifestPath, true);
        $blockers = array_merge(
            $blockers,
            $this->evaluateJournalAgainstManifest($journal, $manifestByRefundId, $ownerApprovalRef),
        );

        return $blockers;
    }

    /**
     * @param  Collection<int|string, array<string, mixed>>  $manifestByRefundId
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    private function evaluateJournalAgainstManifest(
        CentralWalletRefundMigration $journal,
        Collection $manifestByRefundId,
        ?string $ownerApprovalRef,
    ): array {
        $blockers = [];
        $refundId = (int) $journal->refund_id;
        $manifestRow = $manifestByRefundId->get($refundId);

        if ($manifestRow === null) {
            return [['code' => 'refund_not_in_manifest', 'refund_id' => $refundId, 'message' => 'Refund missing from manifest']];
        }

        $blockers = array_merge($blockers, $this->evaluateLiveRefundOnly($manifestRow));

        if ($journal->lane !== RefundMigrationLane::Lane1SpokeCutover) {
            $blockers[] = ['code' => 'lane_not_a', 'refund_id' => $refundId, 'message' => 'Expected lane_1_spoke_cutover'];
        }

        if ($journal->status === RefundMigrationStatus::Reconciled) {
            if ($journal->destination_ledger_entry_id !== null) {
                return [[
                    'code' => 'already_reconciled',
                    'refund_id' => $refundId,
                    'message' => 'Migration already reconciled (execution would no-op)',
                ]];
            }
        } elseif ($journal->status !== RefundMigrationStatus::Prepared) {
            $blockers[] = ['code' => 'status_not_prepared', 'refund_id' => $refundId, 'message' => 'Journal must be prepared'];
        }

        if ((string) $journal->refund_reference !== (string) ($manifestRow['desk_refund_reference'] ?? '')) {
            $blockers[] = ['code' => 'refund_reference_mismatch', 'refund_id' => $refundId, 'message' => 'Refund reference mismatch'];
        }

        if (bccomp((string) $journal->amount, (string) ($manifestRow['refund_amount'] ?? '0'), 2) !== 0) {
            $blockers[] = ['code' => 'amount_mismatch', 'refund_id' => $refundId, 'message' => 'Amount mismatch'];
        }

        if ((string) $journal->cwid !== (string) ($manifestRow['cwid'] ?? '')) {
            $blockers[] = ['code' => 'cwid_manifest_mismatch', 'refund_id' => $refundId, 'message' => 'CWID mismatch'];
        }

        if ((string) $journal->desk_customer_id !== (string) ($manifestRow['desk_customer_id'] ?? '')) {
            $blockers[] = ['code' => 'desk_customer_manifest_mismatch', 'refund_id' => $refundId, 'message' => 'Desk customer mismatch'];
        }

        if ((int) $journal->source_wallet_id !== (int) ($manifestRow['source_wallet_id'] ?? 0)) {
            $blockers[] = ['code' => 'source_wallet_mismatch', 'refund_id' => $refundId, 'message' => 'Source wallet mismatch'];
        }

        $localUserId = (string) ($journal->metadata['order_resolved_user_id'] ?? '');
        if ($localUserId !== (string) ($manifestRow['order_resolved_user_id'] ?? '')) {
            $blockers[] = ['code' => 'local_user_mismatch', 'refund_id' => $refundId, 'message' => 'order_resolved_user_id mismatch'];
        }

        if ($journal->destination_ledger_entry_id !== null) {
            $blockers[] = ['code' => 'destination_ledger_present', 'refund_id' => $refundId, 'message' => 'Migration already linked to ledger entry'];
        }

        $blockers = array_merge($blockers, $this->duplicateLedgerBlockers($journal));

        if ($ownerApprovalRef !== null && $journal->owner_approval_ref !== null && $journal->owner_approval_ref !== $ownerApprovalRef) {
            $blockers[] = ['code' => 'row_owner_approval_mismatch', 'refund_id' => $refundId, 'message' => 'Row owner approval mismatch'];
        }

        $rollbackKey = (string) ($journal->metadata['rollback_idempotency_key'] ?? '');
        if ($rollbackKey !== RefundMigrationIdempotencyKey::rollback($refundId)) {
            $blockers[] = ['code' => 'rollback_key_mismatch', 'refund_id' => $refundId, 'message' => 'Rollback idempotency key mismatch'];
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $manifestRow
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    private function evaluateLiveRefundOnly(array $manifestRow): array
    {
        $blockers = [];
        $refundId = (int) ($manifestRow['refund_id'] ?? 0);

        $existingMigration = CentralWalletRefundMigration::query()
            ->where('refund_id', $refundId)
            ->where('status', RefundMigrationStatus::Reconciled)
            ->first();

        if ($existingMigration !== null) {
            $blockers[] = [
                'code' => 'conflicting_reconciled_migration',
                'refund_id' => $refundId,
                'message' => 'Another reconciled migration row exists for this refund',
            ];
        }

        $refund = RefundRequest::query()->find($refundId);
        if ($refund === null) {
            return [['code' => 'refund_not_found', 'refund_id' => $refundId, 'message' => 'Refund request not found']];
        }

        if ((string) $refund->reference_no !== (string) ($manifestRow['desk_refund_reference'] ?? '')) {
            $blockers[] = ['code' => 'live_refund_reference_mismatch', 'refund_id' => $refundId, 'message' => 'Live refund reference mismatch'];
        }

        if ($refund->approved_refund_method?->value !== 'wallet') {
            $blockers[] = ['code' => 'refund_not_wallet', 'refund_id' => $refundId, 'message' => 'Refund is not wallet method'];
        }

        if (! in_array($refund->status?->value, ['closed', 'completed'], true)) {
            $blockers[] = ['code' => 'refund_not_terminal', 'refund_id' => $refundId, 'message' => 'Refund is not terminal'];
        }

        if (bccomp((string) $refund->refund_amount, (string) ($manifestRow['refund_amount'] ?? '0'), 2) !== 0) {
            $blockers[] = ['code' => 'live_refund_amount_mismatch', 'refund_id' => $refundId, 'message' => 'Live refund amount mismatch'];
        }

        $execId = trim((string) ($refund->execution_transaction_id ?? ''));
        if ($execId !== (string) ($manifestRow['execution_transaction_id'] ?? '')) {
            $blockers[] = ['code' => 'execution_transaction_mismatch', 'refund_id' => $refundId, 'message' => 'execution_transaction_id mismatch'];
        }

        $order = Order::query()->find($refund->order_id);
        if ($order === null) {
            $blockers[] = ['code' => 'order_not_found', 'refund_id' => $refundId, 'message' => 'Order not found'];
        } else {
            if ((string) $order->order_id !== (string) ($manifestRow['order_number'] ?? '')) {
                $blockers[] = ['code' => 'order_number_mismatch', 'refund_id' => $refundId, 'message' => 'Order number mismatch'];
            }

            if ((int) ($manifestRow['order_id'] ?? 0) > 0 && (int) $order->id !== (int) $manifestRow['order_id']) {
                $blockers[] = ['code' => 'order_pk_mismatch', 'refund_id' => $refundId, 'message' => 'Order primary key mismatch'];
            }

            if ((string) $order->customer_id !== (string) ($manifestRow['desk_customer_id'] ?? '')) {
                $blockers[] = ['code' => 'order_customer_mismatch', 'refund_id' => $refundId, 'message' => 'Order customer does not match manifest'];
            }
        }

        $customer = CentralCustomer::query()->find((string) ($manifestRow['desk_customer_id'] ?? ''));
        if ($customer === null) {
            $blockers[] = ['code' => 'desk_customer_not_found', 'refund_id' => $refundId, 'message' => 'Desk customer not found'];
        } elseif ((string) $customer->central_wallet_id !== (string) ($manifestRow['cwid'] ?? '')) {
            $blockers[] = ['code' => 'cwid_customer_mismatch', 'refund_id' => $refundId, 'message' => 'CWID does not belong to Desk customer'];
        }

        $refReference = (string) ($manifestRow['desk_refund_reference'] ?? '');
        if ($refReference !== '') {
            $ledgerExists = CentralWalletLedgerEntry::query()
                ->where('business_reference', $refReference)
                ->where('entry_type', 'credit')
                ->exists();

            if ($ledgerExists) {
                $blockers[] = ['code' => 'duplicate_ledger_credit', 'refund_id' => $refundId, 'message' => 'Desk ledger credit already exists for refund reference'];
            }
        }

        return $blockers;
    }

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    private function duplicateLedgerBlockers(CentralWalletRefundMigration $journal): array
    {
        $blockers = [];
        $refundId = (int) $journal->refund_id;

        $byBusinessReference = CentralWalletLedgerEntry::query()
            ->where('business_reference', $journal->refund_reference)
            ->where('entry_type', 'credit')
            ->exists();

        if ($byBusinessReference) {
            $blockers[] = ['code' => 'duplicate_ledger_credit', 'refund_id' => $refundId, 'message' => 'Ledger credit exists for business_reference'];
        }

        $sourceReference = RefundMigrationIdempotencyKey::ledgerSourceReference($refundId);
        $bySourceReference = CentralWalletLedgerEntry::query()
            ->where('source_reference', $sourceReference)
            ->where('entry_type', 'credit')
            ->exists();

        if ($bySourceReference) {
            $blockers[] = ['code' => 'duplicate_ledger_source_reference', 'refund_id' => $refundId, 'message' => 'Ledger credit exists for refund source reference'];
        }

        return $blockers;
    }
}
