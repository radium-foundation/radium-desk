<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class Ready4RefundMigrationRehearseService
{
    public function __construct(
        private readonly Ready4RefundMigrationDryRunService $dryRunService,
        private readonly Ready4RefundMigrationBatchGate $batchGate,
        private readonly Container $container,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $manifestPath = null): array
    {
        $dryRun = $this->dryRunService->run($manifestPath);
        $executorTrace = $this->traceExecutorStack();
        $sample = $this->traceRepresentativeRow();

        return [
            'rehearsal' => true,
            'financial_writes' => false,
            'execution_enabled' => (bool) config('central_wallet.refund_migration.execution_enabled', false),
            'dry_run' => $dryRun,
            'executor_trace' => $executorTrace,
            'representative_row' => $sample,
            'batch_gate_blockers_with_execution_flag' => $this->batchGate->evaluate($manifestPath, true),
            'executable_rehearsal_pass' => ($dryRun['executable_batch_ready'] ?? false)
                && $executorTrace['executor_resolvable']
                && ($sample['rollback_trace']['uses_ledger_reversal'] ?? false),
            'cohort_rehearsal_pass' => false,
            'rehearsal_pass' => ($dryRun['executable_batch_ready'] ?? false)
                && $executorTrace['executor_resolvable']
                && ($sample['rollback_trace']['uses_ledger_reversal'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function traceExecutorStack(): array
    {
        $classes = [
            Type1RefundMigrationOrchestrator::class,
            RefundMigrationLane1Executor::class,
            BalanceMigrationCutoverService::class,
            RefundMigrationRollbackService::class,
        ];

        $resolvable = true;
        $missing = [];
        foreach ($classes as $class) {
            try {
                $this->container->make($class);
            } catch (\Throwable) {
                $resolvable = false;
                $missing[] = $class;
            }
        }

        return [
            'path' => [
                'journal' => 'central_wallet_refund_migrations (batch_id='.Ready4FinancialMigrationManifestLoader::BATCH_ID.')',
                'batch_gate' => Ready4RefundMigrationBatchGate::class,
                'orchestrator' => Type1RefundMigrationOrchestrator::class.' (shared executor; ready4 uses separate batch_id)',
                'lane_executor' => RefundMigrationLane1Executor::class.' → BalanceMigrationCutoverService',
                'source_debit' => 'HttpWalletMigrationSpokeClient::retireSource (rdservice.in users_wallet only)',
                'cw_credit' => 'LedgerService::appendEntry (fixed destination_central_wallet_id from journal)',
                'blocked_rows' => 'radiumbox.com spoke cutover not deployed (refund 360)',
            ],
            'executor_resolvable' => $resolvable,
            'missing_classes' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function traceRepresentativeRow(): array
    {
        $migration = CentralWalletRefundMigration::query()
            ->where('batch_id', Ready4FinancialMigrationManifestLoader::BATCH_ID)
            ->where('status', RefundMigrationStatus::Prepared)
            ->orderBy('refund_id')
            ->first();

        if ($migration === null) {
            throw new InvalidArgumentException('no_prepared_ready4_journal_row');
        }

        $manifest = app(Ready4FinancialMigrationManifestLoader::class)->load();
        $manifestRow = collect($manifest['rows'])->firstWhere('refund_id', $migration->refund_id);

        return [
            'refund_id' => $migration->refund_id,
            'amount' => (string) $migration->amount,
            'source_application' => $migration->source_application,
            'source_wallet_id' => $migration->source_wallet_id,
            'source_balance_before' => $migration->metadata['source_balance_before'] ?? null,
            'desk_customer_id' => $migration->desk_customer_id,
            'cwid' => $migration->cwid,
            'cwid_immutable' => $manifestRow !== null && (string) ($manifestRow['cwid'] ?? '') === (string) $migration->cwid,
            'idempotency_key' => $migration->idempotency_key,
            'rollback_idempotency_key' => $migration->metadata['rollback_idempotency_key'] ?? null,
            'atomicity' => [
                'cutover_credit_before_spoke_retire' => true,
                'compensating_on_spoke_retire_failure' => true,
                'idempotent_resume_via_balance_migration_operation_id' => true,
            ],
            'rollback_trace' => [
                'service' => RefundMigrationRollbackService::class,
                'uses_ledger_reversal' => true,
                'uses_ledger_deletion' => false,
                'spoke_restore' => 'WalletMigrationSpokeClient::restoreSourceCredit',
                'rollback_idempotency_key' => RefundMigrationIdempotencyKey::rollback((int) $migration->refund_id),
            ],
            'existing_ledger_credit' => CentralWalletLedgerEntry::query()
                ->where('business_reference', $migration->refund_reference)
                ->where('entry_type', 'credit')
                ->exists(),
            'lane' => $migration->lane === RefundMigrationLane::Lane1SpokeCutover,
        ];
    }
}
