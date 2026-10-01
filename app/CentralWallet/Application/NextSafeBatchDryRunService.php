<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class NextSafeBatchDryRunService
{
    public function __construct(
        private readonly NextSafeBatchManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $batchId = (string) $manifest['batch_id'];

        $journalRows = CentralWalletRefundMigration::query()
            ->where('batch_id', $batchId)
            ->orderBy('refund_id')
            ->get();

        $manifestCount = count($manifest['rows']);
        $manifestAmount = (string) ($manifest['refund_amount'] ?? '0.00');

        $journalTotal = $journalRows->reduce(
            static fn (string $carry, CentralWalletRefundMigration $row): string => bcadd($carry, (string) $row->amount, 2),
            '0.00',
        );

        $executionEnabled = (bool) config('central_wallet.refund_migration.execution_enabled', false);

        return [
            'dry_run' => true,
            'batch_id' => $batchId,
            'manifest_count' => $manifestCount,
            'manifest_amount' => $manifestAmount,
            'manifest_rows_sha256' => $manifest['manifest_rows_sha256'] ?? null,
            'journal_count' => $journalRows->count(),
            'journal_amount' => $journalTotal,
            'expected_spoke_debit' => $journalTotal,
            'expected_cw_credits' => $journalTotal,
            'duplicate_ledger_credit_count' => $this->duplicateLedgerCredits($journalRows),
            'financial_variance' => bcsub($journalTotal, $manifestAmount, 2),
            'batch_ready' => $manifestCount === 0
                && $journalRows->count() === 0
                && ! $executionEnabled,
            'batch_empty' => $manifestCount === 0,
            'execution_flag_enabled' => $executionEnabled,
            'financial_execution_performed' => false,
            'blockers' => $manifestCount === 0
                ? ['no_deterministic_batch_candidates']
                : [],
            'rows' => [],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CentralWalletRefundMigration>  $journalRows
     */
    private function duplicateLedgerCredits($journalRows): int
    {
        if ($journalRows->isEmpty()) {
            return 0;
        }

        return CentralWalletLedgerEntry::query()
            ->where('source_system', 'radium-desk')
            ->where('entry_type', 'credit')
            ->whereIn('source_reference', $journalRows->pluck('source_reference')->all())
            ->count();
    }
}
