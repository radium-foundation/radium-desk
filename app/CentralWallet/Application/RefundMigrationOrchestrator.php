<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RefundMigrationOrchestrator
{
    public function __construct(
        private readonly RefundMigrationBatchGate $batchGate,
        private readonly RefundMigrationLane1Executor $lane1Executor,
        private readonly RefundProvenanceMigrationService $provenanceService,
    ) {}

    /**
     * @return array{processed: int, results: list<array<string, mixed>>}
     */
    public function executeBatch(string $ownerApprovalRef, ?string $manifestPath = null): array
    {
        $this->batchGate->assertReady($ownerApprovalRef, $manifestPath);

        $rows = CentralWalletRefundMigration::query()
            ->where('status', RefundMigrationStatus::Prepared)
            ->orderBy('refund_id')
            ->get();

        if ($rows->count() !== RefundMigrationManifestLoader::EXPECTED_COUNT) {
            throw new InvalidArgumentException('batch_not_fully_prepared');
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
        string $actorId = 'refund_migration_orchestrator',
    ): array {
        if ($migration->status !== RefundMigrationStatus::Prepared) {
            throw new InvalidArgumentException('migration_not_prepared');
        }

        $correlationId ??= (string) Str::uuid();

        return match ($migration->lane) {
            RefundMigrationLane::Lane1SpokeCutover => $this->lane1Executor->execute(
                $migration,
                $ownerApprovalRef,
                $correlationId,
                $actorId,
            ),
            RefundMigrationLane::Lane3RefundProvenance => $this->provenanceService->execute(
                $migration,
                $ownerApprovalRef,
                $correlationId,
                $actorId,
            ),
            default => throw new InvalidArgumentException('lane_not_executable:'.$migration->lane->value),
        };
    }
}
