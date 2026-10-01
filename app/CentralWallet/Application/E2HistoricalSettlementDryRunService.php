<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class E2HistoricalSettlementDryRunService
{
    public function __construct(
        private readonly E2HistoricalSettlementManifestLoader $manifestLoader,
        private readonly E2HistoricalSettlementBatchGate $batchGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $manifestPath = null, ?string $ownerApprovalRef = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $journalRows = CentralWalletRefundMigration::query()
            ->where('batch_id', E2HistoricalSettlementManifestLoader::BATCH_ID)
            ->orderBy('refund_id')
            ->get();

        $blockers = $this->batchGate->evaluate($manifestPath, false, $ownerApprovalRef);
        $executable = 0;
        $rows = [];

        foreach ($journalRows as $journal) {
            $rowBlockers = array_values(array_filter(
                $blockers,
                static fn (array $b): bool => ($b['refund_id'] ?? null) === (int) $journal->refund_id,
            ));

            $isExecutable = $rowBlockers === []
                && $journal->lane === RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement
                && $journal->status === RefundMigrationStatus::Prepared
                && $journal->desk_customer_id !== null
                && $journal->cwid !== null;

            if ($isExecutable) {
                $executable++;
            }

            $rows[] = [
                'refund_id' => $journal->refund_id,
                'amount' => (string) $journal->amount,
                'lane' => $journal->lane->value,
                'status' => $journal->status->value,
                'desk_customer_id' => $journal->desk_customer_id,
                'cwid' => $journal->cwid,
                'settlement_classification' => $journal->metadata['settlement_classification'] ?? null,
                'blocked_reason' => $journal->metadata['blocked_reason'] ?? null,
                'executable' => $isExecutable,
                'blockers' => $rowBlockers,
            ];
        }

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        $duplicateLedgerCredits = CentralWalletLedgerEntry::query()
            ->where('source_system', 'radium-desk')
            ->where('entry_type', 'credit')
            ->whereIn('source_reference', $journalRows->pluck('source_reference')->all())
            ->count();

        return [
            'dry_run' => true,
            'batch_id' => E2HistoricalSettlementManifestLoader::BATCH_ID,
            'cohort_id' => E2HistoricalSettlementManifestLoader::COHORT_ID,
            'settlement_classification' => $manifest['settlement_classification'] ?? null,
            'owner_approval_ref' => $manifest['owner_approval_ref'] ?? null,
            'forensic_report_ref' => $manifest['forensic_report_ref'] ?? null,
            'manifest_rows_sha256' => $manifest['manifest_rows_sha256'] ?? null,
            'manifest_count' => count($manifest['rows']),
            'manifest_amount' => E2HistoricalSettlementManifestLoader::EXPECTED_AMOUNT,
            'journal_count' => $journalRows->count(),
            'journal_amount' => $journalTotal,
            'executable_count' => $executable,
            'blocked_count' => $journalRows->count() - $executable,
            'expected_spoke_debit' => '0.00',
            'expected_cw_credits' => $executable > 0 ? $journalTotal : '0.00',
            'duplicate_ledger_credit_count' => $duplicateLedgerCredits,
            'financial_variance' => bcsub($journalTotal, E2HistoricalSettlementManifestLoader::EXPECTED_AMOUNT, 2),
            'batch_gate_blockers' => $blockers,
            'batch_executable' => $blockers === [] && $executable === E2HistoricalSettlementManifestLoader::EXPECTED_COUNT,
            'financial_execution_performed' => false,
            'source_wallet_provenance' => 'unavailable_not_reconstructed',
            'rows' => $rows,
        ];
    }
}
