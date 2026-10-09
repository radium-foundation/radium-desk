<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PilotRefundMigrationOrchestrator
{
    public function __construct(
        private readonly PilotRefundMigrationPreflightGate $preflightGate,
        private readonly PilotRefundMigrationManifestLoader $manifestLoader,
        private readonly RefundMigrationLane1Executor $lane1Executor,
    ) {}

    /**
     * @return array{blockers: list<array<string, mixed>>, ready: bool, manifest: array<string, mixed>}
     */
    public function preflight(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $blockers = $this->preflightGate->evaluateManifest($manifestPath, false);

        $blocking = array_values(array_filter(
            $blockers,
            static fn (array $row): bool => ($row['code'] ?? '') !== 'already_reconciled',
        ));

        return [
            'blockers' => $blockers,
            'ready' => $blocking === [],
            'manifest' => [
                'batch_id' => $manifest['batch_id'],
                'cohort_id' => $manifest['cohort_id'],
                'refund_count' => $manifest['refund_count'],
                'manifest_rows_sha256' => $manifest['manifest_rows_sha256'],
                'allowed_refund_ids' => $manifest['allowed_refund_ids'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executeSingle(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        ?string $manifestPath = null,
        ?string $correlationId = null,
        string $actorId = 'pilot_refund_migration_orchestrator',
    ): array {
        if (! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            throw new InvalidArgumentException('refund_migration_execution_disabled');
        }

        if (! (bool) config('central_wallet.balance_migration.execution_enabled', false)) {
            throw new InvalidArgumentException('balance_migration_execution_disabled');
        }

        $this->assertAllowlistedRefundId((int) $migration->refund_id, $manifestPath);

        if ($migration->status === RefundMigrationStatus::Reconciled) {
            return [
                'status' => $migration->status->value,
                'migration_operation_id' => $migration->balance_migration_operation_id,
                'ledger_entry_id' => $migration->destination_ledger_entry_id,
                'idempotent_replay' => true,
            ];
        }

        if ($migration->lane !== RefundMigrationLane::Lane1SpokeCutover) {
            throw new InvalidArgumentException('pilot_requires_lane_1');
        }

        $blockers = $this->preflightGate->evaluateExecution($migration, $ownerApprovalRef, $manifestPath);
        $hardBlockers = array_values(array_filter(
            $blockers,
            static fn (array $row): bool => ! in_array($row['code'] ?? '', ['already_reconciled'], true),
        ));

        if ($hardBlockers !== []) {
            throw new InvalidArgumentException('pilot_preflight_failed:'.json_encode($hardBlockers, JSON_THROW_ON_ERROR));
        }

        if ($migration->status !== RefundMigrationStatus::Prepared) {
            throw new InvalidArgumentException('migration_not_prepared');
        }

        $correlationId ??= (string) Str::uuid();

        return $this->lane1Executor->execute(
            $migration,
            $ownerApprovalRef,
            $correlationId,
            $actorId,
        );
    }

    private function assertAllowlistedRefundId(int $refundId, ?string $manifestPath): void
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $allowed = $this->manifestLoader->normalizedAllowlist($manifest);

        if (! in_array($refundId, $allowed, true)) {
            throw new InvalidArgumentException('pilot_refund_id_not_allowlisted');
        }
    }
}
