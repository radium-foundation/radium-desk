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
}
