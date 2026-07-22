<?php

namespace App\Support\Repair\Services;

use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Support\Repair\Data\RepairActionOutcome;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Models\SystemRepairBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class RepairAuditBridge
{
    public const EVENT_REPAIRED = 'system.repair.item_repaired';

    public const EVENT_ROLLED_BACK = 'system.repair.item_rolled_back';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function logItemRepaired(
        SystemRepairBatch $batch,
        RepairCandidate $candidate,
        RepairActionOutcome $outcome,
    ): void {
        $this->safeLog(
            event: self::EVENT_REPAIRED,
            auditable: $candidate->subject,
            oldValues: [],
            newValues: [
                'repair_batch_uuid' => $batch->uuid,
                'repair_key' => $batch->repair_key,
                'repair_action' => $outcome->action,
                'repair_category' => $outcome->category,
                'outcome' => $outcome->outcome->value,
                'related_id' => $candidate->relatedId(),
                'command' => 'repair-framework',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $before
     */
    public function logItemRolledBack(
        SystemRepairBatch $batch,
        Model $subject,
        array $before,
    ): void {
        $this->safeLog(
            event: self::EVENT_ROLLED_BACK,
            auditable: $subject,
            oldValues: [],
            newValues: [
                'repair_batch_uuid' => $batch->uuid,
                'repair_key' => $batch->repair_key,
                'restored' => $before,
                'command' => 'repair-framework',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function safeLog(string $event, Model $auditable, array $oldValues, array $newValues): void
    {
        try {
            $actor = $this->automationIdentity->systemUser();
            $this->auditLogService->log(
                userId: $actor->id,
                event: $event,
                auditable: $auditable,
                oldValues: $oldValues,
                newValues: $newValues,
            );
        } catch (Throwable $exception) {
            Log::warning('system_repair.audit_failed', [
                'event' => $event,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
