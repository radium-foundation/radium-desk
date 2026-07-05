<?php

namespace App\Services\RadiumBox;

use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;

class RadiumBoxSyncAuditService
{
    public const EVENT_MANUAL_SYNC = 'radiumbox.sync.manual';

    public const EVENT_SCHEDULER_RECOVERY = 'radiumbox.sync.scheduler_recovery';

    public const EVENT_BACKGROUND_COMPLETED = 'radiumbox.sync.background_completed';

    public const EVENT_ENRICHMENT_STARTED = 'radiumbox.enrichment_started';

    public const EVENT_ENRICHMENT_COMPLETED = 'radiumbox.enrichment_completed';

    public const EVENT_ENRICHMENT_FAILED = 'radiumbox.enrichment_failed';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordManualSync(
        Order $order,
        User $actor,
        bool $success,
        float $durationMs,
        string $previousStatus,
        string $newStatus,
        ?string $errorMessage = null,
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_MANUAL_SYNC,
            auditable: $order,
            newValues: array_filter([
                'success' => $success,
                'duration_ms' => round($durationMs, 2),
                'previous_sync_status' => $previousStatus,
                'new_sync_status' => $newStatus,
                'sync_source' => 'manual',
                'error_message' => $errorMessage,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    public function recordSchedulerRecovery(Order $order, string $previousStatus): void
    {
        $this->auditLogService->log(
            userId: null,
            event: self::EVENT_SCHEDULER_RECOVERY,
            auditable: $order,
            newValues: [
                'previous_sync_status' => $previousStatus,
                'new_sync_status' => 'PENDING',
                'sync_source' => 'scheduler',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEnrichmentStarted(Order $order, string $syncSource, int $attempt, array $metadata = []): void
    {
        $this->auditLogService->log(
            userId: null,
            event: self::EVENT_ENRICHMENT_STARTED,
            auditable: $order,
            newValues: array_filter([
                'sync_source' => $syncSource,
                'attempt' => $attempt,
                'cashfree_verified' => $order->isCashfreeVerified(),
                ...$metadata,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEnrichmentCompleted(
        Order $order,
        string $syncSource,
        int $attempt,
        array $fieldsApplied,
        array $metadata = [],
    ): void {
        $this->auditLogService->log(
            userId: null,
            event: self::EVENT_ENRICHMENT_COMPLETED,
            auditable: $order,
            newValues: array_filter([
                'sync_source' => $syncSource,
                'attempt' => $attempt,
                'fields_applied' => $fieldsApplied,
                'cashfree_verified' => $order->isCashfreeVerified(),
                ...$metadata,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEnrichmentFailed(
        Order $order,
        string $syncSource,
        int $attempt,
        ?string $errorMessage = null,
        array $metadata = [],
    ): void {
        $this->auditLogService->log(
            userId: null,
            event: self::EVENT_ENRICHMENT_FAILED,
            auditable: $order,
            newValues: array_filter([
                'sync_source' => $syncSource,
                'attempt' => $attempt,
                'error_message' => $errorMessage,
                'cashfree_verified' => $order->isCashfreeVerified(),
                ...$metadata,
            ], fn (mixed $value): bool => $value !== null),
        );
    }
}
