<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class Ready4RefundMigrationOrchestrator
{
    /** @var list<int> */
    public const EXECUTABLE_REFUND_IDS = [268, 284, 336];

    public function __construct(
        private readonly Ready4RefundMigrationBatchGate $batchGate,
        private readonly RefundMigrationLane1Executor $lane1Executor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function executeSingle(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        ?string $correlationId = null,
        string $actorId = 'ready4_refund_migration_orchestrator',
        ?string $manifestPath = null,
    ): array {
        if (! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            throw new InvalidArgumentException('refund_migration_execution_disabled');
        }

        $refundId = (int) $migration->refund_id;
        $this->assertExecutableRefundId($refundId);

        if ($migration->batch_id !== Ready4FinancialMigrationManifestLoader::BATCH_ID) {
            throw new InvalidArgumentException('not_ready4_migration_row');
        }

        if ($migration->status === RefundMigrationStatus::Reconciled) {
            return [
                'status' => $migration->status->value,
                'migration_operation_id' => $migration->balance_migration_operation_id,
                'ledger_entry_id' => $migration->destination_ledger_entry_id,
                'idempotent_replay' => true,
            ];
        }

        if ($migration->status !== RefundMigrationStatus::Prepared) {
            throw new InvalidArgumentException('migration_not_prepared');
        }

        if ($migration->lane !== RefundMigrationLane::Lane1SpokeCutover) {
            throw new InvalidArgumentException('ready4_requires_lane_1');
        }

        $blockers = $this->batchGate->evaluateSingle($migration, $manifestPath);
        if ($blockers !== []) {
            throw new InvalidArgumentException('ready4_row_gate_failed:'.json_encode($blockers, JSON_THROW_ON_ERROR));
        }

        $correlationId ??= (string) Str::uuid();

        return $this->lane1Executor->execute(
            $migration,
            $ownerApprovalRef,
            $correlationId,
            $actorId,
        );
    }

    private function assertExecutableRefundId(int $refundId): void
    {
        if (! in_array($refundId, self::EXECUTABLE_REFUND_IDS, true)) {
            throw new InvalidArgumentException('ready4_refund_id_not_allowed:'.$refundId);
        }
    }
}
