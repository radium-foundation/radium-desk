<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class Ready4RefundMigrationBatchGate
{
    public function __construct(
        private readonly Ready4FinancialMigrationManifestLoader $manifestLoader,
    ) {}

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    public function evaluate(?string $manifestPath = null, bool $requireExecutionFlag = false): array
    {
        $blockers = [];

        if ($requireExecutionFlag && ! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $blockers[] = [
                'code' => 'refund_migration_execution_disabled',
                'message' => 'Execution flag is disabled',
            ];
        }

        try {
            $manifest = $this->manifestLoader->load($manifestPath);
        } catch (InvalidArgumentException $exception) {
            return [['code' => $exception->getMessage(), 'message' => 'Manifest validation failed']];
        }

        $journalRows = CentralWalletRefundMigration::query()
            ->where('batch_id', Ready4FinancialMigrationManifestLoader::BATCH_ID)
            ->orderBy('refund_id')
            ->get();

        if ($journalRows->count() !== Ready4FinancialMigrationManifestLoader::EXECUTABLE_COUNT) {
            $blockers[] = [
                'code' => 'journal_count_mismatch',
                'message' => 'Expected '.Ready4FinancialMigrationManifestLoader::EXECUTABLE_COUNT.' journal rows, found '.$journalRows->count(),
            ];
        }

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        if (bccomp($journalTotal, Ready4FinancialMigrationManifestLoader::EXECUTABLE_AMOUNT, 2) !== 0) {
            $blockers[] = [
                'code' => 'journal_amount_mismatch',
                'message' => 'Journal total '.$journalTotal.' != '.Ready4FinancialMigrationManifestLoader::EXECUTABLE_AMOUNT,
            ];
        }

        $manifestByRefundId = collect($manifest['rows'])->keyBy('refund_id');

        foreach ($journalRows as $journal) {
            $blockers = array_merge($blockers, $this->evaluateRow($journal, $manifestByRefundId));
        }

        $this->assertNoDuplicateLedgerCredits($journalRows, $blockers);

        foreach ($manifest['blocked_rows'] ?? [] as $blocked) {
            $blockers[] = [
                'code' => 'cohort_row_blocked',
                'refund_id' => (int) ($blocked['refund_id'] ?? 0),
                'message' => (string) ($blocked['reason'] ?? 'blocked'),
            ];
        }

        return $blockers;
    }

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    public function evaluateSingle(
        CentralWalletRefundMigration $journal,
        ?string $manifestPath = null,
    ): array {
        try {
            $manifest = $this->manifestLoader->load($manifestPath);
        } catch (InvalidArgumentException $exception) {
            return [['code' => $exception->getMessage(), 'message' => 'Manifest validation failed']];
        }

        if ($journal->batch_id !== Ready4FinancialMigrationManifestLoader::BATCH_ID) {
            return [['code' => 'batch_id_mismatch', 'refund_id' => (int) $journal->refund_id, 'message' => 'Not a Ready4 batch row']];
        }

        $manifestByRefundId = collect($manifest['rows'])->keyBy('refund_id');

        return $this->evaluateRow($journal, $manifestByRefundId);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $manifestByRefundId
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    private function evaluateRow(
        CentralWalletRefundMigration $journal,
        Collection $manifestByRefundId,
    ): array {
        $blockers = [];
        $refundId = (int) $journal->refund_id;
        $manifestRow = $manifestByRefundId->get($refundId);

        if ($manifestRow === null) {
            return [['code' => 'refund_not_in_manifest', 'refund_id' => $refundId, 'message' => 'Refund missing from manifest']];
        }

        if (($manifestRow['status'] ?? '') !== 'prepared') {
            return [['code' => 'manifest_row_not_executable', 'refund_id' => $refundId, 'message' => 'Manifest row is not prepared']];
        }

        if ($journal->lane !== RefundMigrationLane::Lane1SpokeCutover) {
            $blockers[] = ['code' => 'lane_not_a', 'refund_id' => $refundId, 'message' => 'Expected Lane 1 spoke cutover'];
        }

        if ($journal->desk_customer_id === null || $journal->cwid === null) {
            $blockers[] = ['code' => 'missing_target_identity', 'refund_id' => $refundId, 'message' => 'Desk Customer / CWID required'];
        }

        if ((string) $journal->cwid !== (string) ($manifestRow['cwid'] ?? '')) {
            $blockers[] = ['code' => 'cwid_manifest_mismatch', 'refund_id' => $refundId, 'message' => 'CWID does not match manifest'];
        }

        if ($journal->source_wallet_id === null || $journal->source_application !== 'rdservice.in') {
            $blockers[] = ['code' => 'missing_spoke_source', 'refund_id' => $refundId, 'message' => 'rdservice.in source wallet required'];
        }

        if ((int) $journal->source_wallet_id !== (int) ($manifestRow['source_wallet_id'] ?? 0)) {
            $blockers[] = ['code' => 'source_wallet_mismatch', 'refund_id' => $refundId, 'message' => 'Source wallet does not match manifest'];
        }

        $balanceBefore = (string) ($journal->metadata['source_balance_before'] ?? '');
        if (bccomp($balanceBefore, (string) $journal->amount, 2) !== 0) {
            $blockers[] = ['code' => 'source_balance_insufficient', 'refund_id' => $refundId, 'message' => 'Recorded source balance does not equal refund amount'];
        }

        if ($journal->status !== RefundMigrationStatus::Prepared) {
            $blockers[] = ['code' => 'status_not_prepared', 'refund_id' => $refundId, 'message' => 'Journal row must be prepared'];
        }

        if ($journal->desk_customer_id !== null && $journal->cwid !== null) {
            $customer = CentralCustomer::query()->find($journal->desk_customer_id);
            if ($customer === null || $customer->central_wallet_id !== $journal->cwid) {
                $blockers[] = ['code' => 'cwid_customer_mismatch', 'refund_id' => $refundId, 'message' => 'CWID does not belong to Desk Customer'];
            }
        }

        if ($journal->destination_ledger_entry_id !== null) {
            $blockers[] = ['code' => 'already_credited', 'refund_id' => $refundId, 'message' => 'Refund already has a Central Wallet credit'];
        }

        $rollbackKey = (string) ($journal->metadata['rollback_idempotency_key'] ?? '');
        if ($rollbackKey === '') {
            $blockers[] = ['code' => 'rollback_key_missing', 'refund_id' => $refundId, 'message' => 'Rollback idempotency key required'];
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
                ->where('business_reference', $journal->refund_reference)
                ->where('entry_type', 'credit')
                ->exists();

            if ($exists) {
                $blockers[] = [
                    'code' => 'duplicate_ledger_credit',
                    'refund_id' => (int) $journal->refund_id,
                    'message' => 'Ledger credit already exists',
                ];
            }
        }
    }
}
