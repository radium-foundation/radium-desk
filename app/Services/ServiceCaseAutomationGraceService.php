<?php

namespace App\Services;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceCaseAutomationGraceService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingService $settingService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function beginGracePeriod(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        $incident = $incident->fresh(['order', 'assignee']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        if ($incident->automation_pending_until !== null) {
            return $this->tryAssignAfterValidation($incident, $actor, $at) ?? $incident->fresh(['assignee', 'order']);
        }

        $graceSeconds = max(0, $this->settingService->getInt('assignment.automation_grace_period_seconds', 60));
        $pendingUntil = now()->addSeconds($graceSeconds);

        $incident->update([
            'automation_pending_until' => $pendingUntil,
            'updated_by' => $actor->id,
        ]);

        $freshIncident = $incident->fresh(['order', 'assignee']);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'service_case.automation_pending',
            auditable: $freshIncident,
            oldValues: [
                'automation_pending_until' => null,
            ],
            newValues: [
                'automation_pending_until' => $pendingUntil->toIso8601String(),
                'grace_period_seconds' => $graceSeconds,
            ],
        );

        return $this->tryAssignAfterValidation($freshIncident, $actor, $at)
            ?? $freshIncident->fresh(['assignee', 'order']);
    }

    public function tryAssignAfterValidation(Incident $incident, User $actor, ?Carbon $at = null): ?Incident
    {
        $incident = $incident->fresh(['order', 'assignee']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        if ($incident->automation_pending_until === null) {
            return null;
        }

        if (now()->greaterThan($incident->automation_pending_until)) {
            return null;
        }

        if (! $this->passesAutomationValidation($incident)) {
            return null;
        }

        return $this->assignmentService->assignToShiftAdminAfterValidation(
            incident: $incident,
            actor: $actor,
            at: $at,
        );
    }

    public function processOrderEnrichmentCompleted(Order $order): void
    {
        $order->loadMissing('incidents.creator');

        Incident::query()
            ->where('order_id', $order->id)
            ->whereNull('assigned_to_user_id')
            ->whereNotNull('automation_pending_until')
            ->with('creator')
            ->each(function (Incident $incident): void {
                $actor = $incident->creator;

                if ($actor === null) {
                    return;
                }

                $this->tryAssignAfterValidation($incident, $actor);
            });
    }

    public function processExpiredGracePeriods(): int
    {
        $processed = 0;

        $expiredIds = Incident::query()
            ->automationGraceExpired()
            ->orderBy('id')
            ->pluck('id');

        foreach ($expiredIds as $incidentId) {
            if ($this->processSingleExpiredGracePeriod((int) $incidentId)) {
                $processed++;
            }
        }

        return $processed;
    }

    public function passesAutomationValidation(Incident $incident): bool
    {
        $order = $incident->order;

        if ($order === null) {
            return false;
        }

        if (! filled(trim((string) $order->serial_number))) {
            return false;
        }

        $syncStatus = $this->syncStore->status($order->id);

        if ($syncStatus === null) {
            return true;
        }

        return $syncStatus === RadiumBoxEnrichmentSyncStatus::Synced;
    }

    private function processSingleExpiredGracePeriod(int $incidentId): bool
    {
        return DB::transaction(function () use ($incidentId): bool {
            $incident = Incident::query()
                ->whereKey($incidentId)
                ->lockForUpdate()
                ->with(['order', 'creator'])
                ->first();

            if ($incident === null) {
                return false;
            }

            if ($incident->assigned_to_user_id !== null || $incident->automation_pending_until === null) {
                return false;
            }

            if ($incident->automation_pending_until->isFuture()) {
                return false;
            }

            $actor = $incident->creator;

            if ($actor === null) {
                return false;
            }

            if ($this->passesAutomationValidation($incident)) {
                $this->assignmentService->assignToShiftAdminAfterValidation(
                    incident: $incident,
                    actor: $actor,
                );

                return true;
            }

            $this->assignmentService->assignViaRoundRobinAfterGracePeriod(
                incident: $incident,
                actor: $actor,
            );

            return true;
        });
    }
}
