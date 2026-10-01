<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class E2HistoricalSettlementOrchestrator
{
    public function __construct(
        private readonly E2HistoricalSettlementBatchGate $batchGate,
        private readonly E2HistoricalManualRefundSettlementService $settlementService,
    ) {}

    /**
     * @return array{processed: int, results: list<array<string, mixed>>}
     */
    public function executeBatch(string $ownerApprovalRef, ?string $manifestPath = null): array
    {
        if (! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            throw new InvalidArgumentException('refund_migration_execution_disabled');
        }

        $blockers = $this->batchGate->evaluate($manifestPath, true, $ownerApprovalRef);
        if ($blockers !== []) {
            throw new InvalidArgumentException('batch_gate_failed:'.json_encode($blockers, JSON_THROW_ON_ERROR));
        }

        $rows = CentralWalletRefundMigration::query()
            ->where('batch_id', E2HistoricalSettlementManifestLoader::BATCH_ID)
            ->where('status', RefundMigrationStatus::Prepared)
            ->orderBy('refund_id')
            ->get();

        if ($rows->count() !== E2HistoricalSettlementManifestLoader::EXPECTED_COUNT) {
            throw new InvalidArgumentException('e2_historical_settlement_batch_not_fully_prepared');
        }

        $results = [];
        foreach ($rows as $migration) {
            $results[] = $this->executeSingle($migration, $ownerApprovalRef);
        }

        return [
            'processed' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executeSingle(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        ?string $correlationId = null,
        string $actorId = 'e2_historical_settlement_orchestrator',
    ): array {
        if (! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            throw new InvalidArgumentException('refund_migration_execution_disabled');
        }

        if ($migration->batch_id !== E2HistoricalSettlementManifestLoader::BATCH_ID) {
            throw new InvalidArgumentException('not_e2_historical_settlement_row');
        }

        if ($migration->status !== RefundMigrationStatus::Prepared) {
            throw new InvalidArgumentException('migration_not_prepared');
        }

        if ($migration->lane !== RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement) {
            throw new InvalidArgumentException('e2_historical_settlement_requires_lane_4');
        }

        $correlationId ??= (string) Str::uuid();

        return $this->settlementService->execute(
            $migration,
            $ownerApprovalRef,
            $correlationId,
            $actorId,
        );
    }
}
