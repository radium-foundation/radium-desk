<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class RefundMigrationDryRunService
{
    public function __construct(
        private readonly RefundMigrationManifestLoader $manifestLoader,
        private readonly RefundMigrationBatchGate $batchGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $ownerApprovalRef = null, ?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $journalRows = CentralWalletRefundMigration::query()->orderBy('refund_id')->get();

        $laneTotals = [];
        foreach (RefundMigrationLane::cases() as $lane) {
            $laneTotals[$lane->value] = ['count' => 0, 'amount' => '0.00'];
        }

        $rows = [];
        $expectedCwCredits = '0.00';
        $expectedSourceDebits = '0.00';
        $pendingIdentity = 0;
        $pendingAmbiguous = 0;
        $duplicateLedgerCredits = 0;

        foreach ($journalRows as $journal) {
            $lane = $journal->lane->value;
            $laneTotals[$lane]['count']++;
            $laneTotals[$lane]['amount'] = bcadd($laneTotals[$lane]['amount'], (string) $journal->amount, 2);

            if ($journal->desk_customer_id === null || $journal->cwid === null) {
                $pendingIdentity++;
            }

            if ($journal->lane === RefundMigrationLane::Lane2AmbiguityResolution) {
                $pendingAmbiguous++;
            }

            if ($journal->lane === RefundMigrationLane::Lane1SpokeCutover) {
                $expectedSourceDebits = bcadd($expectedSourceDebits, (string) $journal->amount, 2);
            }

            if ($journal->status !== RefundMigrationStatus::Reconciled) {
                $expectedCwCredits = bcadd($expectedCwCredits, (string) $journal->amount, 2);
            }

            $hasDuplicate = CentralWalletLedgerEntry::query()
                ->where('source_system', 'radium-desk')
                ->where('source_reference', $journal->source_reference)
                ->where('entry_type', 'credit')
                ->exists();

            if ($hasDuplicate) {
                $duplicateLedgerCredits++;
            }

            $rows[] = [
                'refund_id' => $journal->refund_id,
                'reference_no' => $journal->refund_reference,
                'amount' => (string) $journal->amount,
                'lane' => $lane,
                'status' => $journal->status->value,
                'desk_customer_id' => $journal->desk_customer_id,
                'cwid' => $journal->cwid,
                'source_application' => $journal->source_application,
                'source_wallet_id' => $journal->source_wallet_id,
                'source_treatment' => $this->sourceTreatment($journal),
                'ready' => $journal->desk_customer_id !== null
                    && $journal->cwid !== null
                    && $journal->status === RefundMigrationStatus::Prepared,
            ];
        }

        $blockers = $ownerApprovalRef !== null
            ? $this->batchGate->evaluate($ownerApprovalRef, $manifestPath)
            : [];

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        return [
            'dry_run' => true,
            'batch_id' => $manifest['batch_id'],
            'manifest_count' => count($manifest['rows']),
            'manifest_amount' => RefundMigrationManifestLoader::EXPECTED_AMOUNT,
            'journal_count' => $journalRows->count(),
            'journal_amount' => $journalTotal,
            'lane_totals' => $laneTotals,
            'expected_cw_credits' => $expectedCwCredits,
            'expected_source_debits' => $expectedSourceDebits,
            'pending_identity_count' => $pendingIdentity,
            'pending_ambiguous_count' => $pendingAmbiguous,
            'duplicate_ledger_credit_count' => $duplicateLedgerCredits,
            'financial_variance' => bcsub($journalTotal, RefundMigrationManifestLoader::EXPECTED_AMOUNT, 2),
            'batch_gate_blockers' => $blockers,
            'batch_ready' => $blockers === [] && $ownerApprovalRef !== null,
            'rows' => $rows,
        ];
    }

    private function sourceTreatment(CentralWalletRefundMigration $journal): string
    {
        return match ($journal->lane) {
            RefundMigrationLane::Lane1SpokeCutover => 'spoke_debit_then_cw_credit',
            RefundMigrationLane::Lane2AmbiguityResolution => 'blocked_pending_owner_spoke_resolution',
            RefundMigrationLane::Lane3RefundProvenance => 'refund_provenance_cw_credit_only',
            RefundMigrationLane::Lane3BlockedInsufficientEvidence => 'blocked_pending_owner_identity',
        };
    }
}
