<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\E2HistoricalSettlementClassification;
use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Models\RefundRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class E2HistoricalSettlementBatchGate
{
    public function __construct(
        private readonly E2HistoricalSettlementManifestLoader $manifestLoader,
    ) {}

    /**
     * @return list<array{code: string, refund_id?: int, message: string}>
     */
    public function evaluate(
        ?string $manifestPath = null,
        bool $requireExecutionFlag = false,
        ?string $ownerApprovalRef = null,
    ): array {
        $blockers = [];

        if ($requireExecutionFlag && ! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $blockers[] = [
                'code' => 'refund_migration_execution_disabled',
                'message' => 'Execution flag is disabled',
            ];
        }

        if ($requireExecutionFlag && trim((string) $ownerApprovalRef) === '') {
            $blockers[] = [
                'code' => 'owner_approval_required',
                'message' => 'Owner approval reference is required',
            ];
        }

        try {
            $manifest = $this->manifestLoader->load($manifestPath);
        } catch (InvalidArgumentException $exception) {
            return [['code' => $exception->getMessage(), 'message' => 'Manifest validation failed']];
        }

        if ($requireExecutionFlag && trim((string) $ownerApprovalRef) !== (string) ($manifest['owner_approval_ref'] ?? '')) {
            $blockers[] = [
                'code' => 'owner_approval_ref_mismatch',
                'message' => 'Owner approval reference does not match manifest',
            ];
        }

        $journalRows = CentralWalletRefundMigration::query()
            ->where('batch_id', E2HistoricalSettlementManifestLoader::BATCH_ID)
            ->orderBy('refund_id')
            ->get();

        if ($journalRows->count() !== E2HistoricalSettlementManifestLoader::EXPECTED_COUNT) {
            $blockers[] = [
                'code' => 'journal_count_mismatch',
                'message' => 'Expected '.E2HistoricalSettlementManifestLoader::EXPECTED_COUNT.' journal rows, found '.$journalRows->count(),
            ];
        }

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        if (bccomp($journalTotal, E2HistoricalSettlementManifestLoader::EXPECTED_AMOUNT, 2) !== 0) {
            $blockers[] = [
                'code' => 'journal_amount_mismatch',
                'message' => 'Journal total '.$journalTotal.' != '.E2HistoricalSettlementManifestLoader::EXPECTED_AMOUNT,
            ];
        }

        $manifestByRefundId = collect($manifest['rows'])->keyBy('refund_id');

        foreach ($journalRows as $journal) {
            $blockers = array_merge($blockers, $this->evaluateRow($journal, $manifestByRefundId));
        }

        $this->assertNoDuplicateLedgerCredits($journalRows, $blockers);

        return $blockers;
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

        if ($journal->lane !== RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement) {
            $blockers[] = ['code' => 'lane_not_historical_settlement', 'refund_id' => $refundId, 'message' => 'Expected Lane 4 historical settlement'];
        }

        if (($journal->metadata['settlement_classification'] ?? '') !== E2HistoricalSettlementClassification::OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT) {
            $blockers[] = ['code' => 'settlement_classification_mismatch', 'refund_id' => $refundId, 'message' => 'Settlement classification mismatch'];
        }

        if ($journal->source_wallet_id !== null) {
            $blockers[] = ['code' => 'source_wallet_must_not_be_set', 'refund_id' => $refundId, 'message' => 'Historical settlement must not claim spoke source wallet'];
        }

        if ($journal->desk_customer_id === null || $journal->cwid === null) {
            $blockers[] = [
                'code' => 'missing_destination_cwid',
                'refund_id' => $refundId,
                'message' => 'Trusted Desk Customer / CWID destination required — cannot infer from unverified historical data',
            ];
        }

        if ($journal->desk_customer_id !== null && $journal->cwid !== null) {
            $customer = CentralCustomer::query()->find($journal->desk_customer_id);
            if ($customer === null || $customer->central_wallet_id !== $journal->cwid) {
                $blockers[] = ['code' => 'cwid_customer_mismatch', 'refund_id' => $refundId, 'message' => 'CWID does not belong to Desk Customer'];
            }

            if ((string) $journal->cwid !== (string) ($manifestRow['cwid'] ?? '')) {
                $blockers[] = ['code' => 'cwid_manifest_mismatch', 'refund_id' => $refundId, 'message' => 'CWID does not match manifest'];
            }

            if ((string) $journal->desk_customer_id !== (string) ($manifestRow['desk_customer_id'] ?? '')) {
                $blockers[] = ['code' => 'desk_customer_manifest_mismatch', 'refund_id' => $refundId, 'message' => 'Desk Customer does not match manifest'];
            }
        }

        if ($journal->status !== RefundMigrationStatus::Prepared) {
            $blockers[] = ['code' => 'status_not_prepared', 'refund_id' => $refundId, 'message' => 'Journal row must be prepared before execution'];
        }

        $refund = RefundRequest::query()->find($refundId);
        if ($refund === null) {
            $blockers[] = ['code' => 'refund_not_found', 'refund_id' => $refundId, 'message' => 'Refund request not found'];
        } elseif (! in_array($refund->status?->value, ['closed', 'completed'], true)) {
            $blockers[] = ['code' => 'refund_not_terminal', 'refund_id' => $refundId, 'message' => 'Refund must remain in terminal closed/completed state'];
        }

        if (bccomp((string) $journal->amount, (string) ($manifestRow['refund_amount'] ?? ''), 2) !== 0) {
            $blockers[] = ['code' => 'amount_manifest_mismatch', 'refund_id' => $refundId, 'message' => 'Amount does not match manifest'];
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
            $existing = CentralWalletLedgerEntry::query()
                ->where('source_system', 'radium-desk')
                ->where('source_reference', $journal->source_reference)
                ->where('entry_type', 'credit')
                ->first();

            if ($existing !== null && $journal->destination_ledger_entry_id === null) {
                $blockers[] = [
                    'code' => 'duplicate_ledger_credit',
                    'refund_id' => (int) $journal->refund_id,
                    'message' => 'Orphan ledger credit already exists for settlement source reference',
                ];
            }
        }
    }
}
