<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Models\RefundRequest;
use InvalidArgumentException;

/**
 * Reusable entry point for paired historical wallet-refund reconciliation (Lane 1).
 * Execution remains gated by config flags and owner approval; this service does not bypass gates.
 */
final class HistoricalWalletRefundReconciliationService
{
    public function __construct(
        private readonly PilotRefundMigrationOrchestrator $orchestrator,
        private readonly WalletRefundAuthoritativeCreditGuard $creditGuard,
    ) {}

    /**
     * @return array{blockers: list<array<string, mixed>>, ready: bool, manifest: array<string, mixed>}
     */
    public function preflight(?string $manifestPath = null): array
    {
        return $this->orchestrator->preflight($manifestPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function executePreparedMigration(
        CentralWalletRefundMigration $migration,
        string $ownerApprovalRef,
        ?string $manifestPath = null,
        ?string $correlationId = null,
        string $actorId = 'historical_wallet_refund_reconciliation',
    ): array {
        return $this->orchestrator->executeSingle(
            $migration,
            $ownerApprovalRef,
            $manifestPath,
            $correlationId,
            $actorId,
        );
    }

    public function assertStandaloneDeskCreditBlockedForMigration(CentralWalletRefundMigration $migration): void
    {
        if ($migration->status === RefundMigrationStatus::Reconciled) {
            return;
        }

        $refund = RefundRequest::query()->find((int) $migration->refund_id);
        if ($refund === null) {
            throw new InvalidArgumentException('migration_refund_missing');
        }

        $this->creditGuard->assertMayPostStandaloneDeskCredit($refund);
    }
}
