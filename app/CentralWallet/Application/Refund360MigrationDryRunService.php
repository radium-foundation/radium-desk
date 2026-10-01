<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class Refund360MigrationDryRunService
{
    public function __construct(
        private readonly Refund360MigrationManifestLoader $manifestLoader,
        private readonly Refund360MigrationBatchGate $batchGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $executableRows = $this->manifestLoader->executableRows($manifestPath);
        $blockedManifestRows = array_values(array_filter(
            $manifest['rows'],
            static fn (array $row): bool => ($row['status'] ?? '') === 'blocked',
        ));

        $journalRows = CentralWalletRefundMigration::query()
            ->where('batch_id', Refund360MigrationManifestLoader::BATCH_ID)
            ->orderBy('refund_id')
            ->get();

        $blockers = $this->batchGate->evaluate($manifestPath, false);
        $ready = 0;
        $rows = [];

        foreach ($journalRows as $journal) {
            $rowBlockers = array_values(array_filter(
                $blockers,
                static fn (array $b): bool => ($b['refund_id'] ?? null) === (int) $journal->refund_id,
            ));

            $isReady = $rowBlockers === []
                && $journal->lane === RefundMigrationLane::Lane1SpokeCutover
                && $journal->status === RefundMigrationStatus::Prepared
                && $journal->desk_customer_id !== null
                && $journal->cwid !== null;

            if ($isReady) {
                $ready++;
            }

            $rows[] = [
                'refund_id' => $journal->refund_id,
                'amount' => (string) $journal->amount,
                'lane' => $journal->lane->value,
                'status' => $journal->status->value,
                'desk_customer_id' => $journal->desk_customer_id,
                'cwid' => $journal->cwid,
                'source_wallet_id' => $journal->source_wallet_id,
                'source_balance_before' => $journal->metadata['source_balance_before'] ?? null,
                'ready' => $isReady,
                'blockers' => $rowBlockers,
            ];
        }

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        $duplicateLedgerCredits = CentralWalletLedgerEntry::query()
            ->where('entry_type', 'credit')
            ->whereIn('business_reference', $journalRows->pluck('refund_reference')->all())
            ->count();

        $executableReady = $ready === Refund360MigrationManifestLoader::EXECUTABLE_COUNT
            && $journalRows->count() === Refund360MigrationManifestLoader::EXECUTABLE_COUNT;

        $executionBlockers = array_values(array_filter(
            $blockers,
            static fn (array $b): bool => ($b['code'] ?? '') !== 'cohort_row_blocked',
        ));

        return [
            'dry_run' => true,
            'batch_id' => Refund360MigrationManifestLoader::BATCH_ID,
            'cohort_id' => Refund360MigrationManifestLoader::COHORT_ID,
            'manifest_count' => count($manifest['rows']),
            'manifest_amount' => Refund360MigrationManifestLoader::EXPECTED_AMOUNT,
            'executable_manifest_count' => count($executableRows),
            'executable_manifest_amount' => Refund360MigrationManifestLoader::EXECUTABLE_AMOUNT,
            'blocked_manifest_count' => count($blockedManifestRows),
            'blocked_manifest_rows' => $blockedManifestRows,
            'journal_count' => $journalRows->count(),
            'journal_amount' => $journalTotal,
            'ready_count' => $ready,
            'blocked_count' => $journalRows->count() - $ready,
            'expected_spoke_debit' => $journalTotal,
            'expected_cw_credits' => $journalTotal,
            'duplicate_ledger_credit_count' => $duplicateLedgerCredits,
            'financial_variance' => '0.00',
            'batch_gate_blockers' => $blockers,
            'executable_batch_ready' => $executableReady && $executionBlockers === [],
            'cohort_batch_ready' => $executableReady
                && $blockers === []
                && count($blockedManifestRows) === 0,
            'batch_ready' => false,
            'financial_execution_performed' => false,
            'rows' => $rows,
        ];
    }
}
