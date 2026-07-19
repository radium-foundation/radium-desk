<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentTrigger;
use App\Support\Assignment\Strategies\ReadyQueueAssignmentStrategy;
use App\Support\Assignment\Strategies\SupportQueueAssignmentStrategy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceCaseAutomationGraceService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingService $settingService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ReadyQueueAssignmentStrategy $readyQueueStrategy,
        private readonly SupportQueueAssignmentStrategy $supportQueueStrategy,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
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
        $order = $incident->order;

        if ($order === null) {
            return null;
        }

        $this->eligibilityService->evaluateAssignmentEligibility($order->fresh(), $actor);

        $freshIncident = $incident->fresh(['assignee', 'order']);

        return $freshIncident->assigned_to_user_id !== null ? $freshIncident : null;
    }

    public function processOrderEnrichmentCompleted(Order $order): void
    {
        $order->loadMissing('incidents.creator');

        $actor = $order->incidents->first()?->creator;

        if ($actor === null) {
            return;
        }

        $this->eligibilityService->evaluateAssignmentEligibility($order->fresh(), $actor);
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

        return $this->eligibilityService->passesValidationForOrder($order);
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
                $this->readyQueueStrategy->assign(
                    AssignmentRequest::make(
                        incident: $incident,
                        actor: $actor,
                        trigger: AssignmentTrigger::GraceExpired,
                    ),
                );

                return true;
            }

            $this->automationMonitor->recordValidationFailed($incident, $actor);

            $order = $incident->order;

            if ($order !== null && $this->eligibilityService->isWaitingForCustomerSerial($order)) {
                $this->automationMonitor->recordWaitingManualCorrection($incident, $actor);
                $this->assignmentService->clearAutomationPending($incident, $actor);

                return true;
            }

            $this->automationMonitor->recordWaitingManualCorrection($incident, $actor);

            $this->supportQueueStrategy->assign(
                AssignmentRequest::make(
                    incident: $incident,
                    actor: $actor,
                    trigger: AssignmentTrigger::GraceExpired,
                ),
            );

            return true;
        });
    }
}
